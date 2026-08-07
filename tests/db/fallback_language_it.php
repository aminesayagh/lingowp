<?php

/**
 * Fallback-language feature end to end:
 *   1. OptionLanguageRegistry::fallbackChain() against the real options table.
 *   2. StructuredRenderResolver's real merge algorithm (post title), reached by
 *      reflection into the shipped private ensureHashLoaded() — same technique
 *      as tests/option_gate_check.php — so this proves the actual production
 *      code resolves the chain, not a re-implementation of it.
 *   3. The simpler tl_html/tl_option "+=" merge used by
 *      PublicRenderPipeline::pageOutputTranslations() and OptionResolver::map().
 *   4. LanguageRestController's fallback_language write guard.
 *   5. CollectionBoard::collectionTexts()'s 'fallback' field — the admin board
 *      preview, which used to hardcode the raw source text regardless of
 *      fallback_language.
 *
 * Run: wp eval-file tests/db/fallback_language_it.php
 */

require __DIR__ . '/_harness.php';

use TransLangs\Board\CollectionBoard;
use TransLangs\Language\LanguageRestController;
use TransLangs\Database\Repository\WpDbSourceRepository;
use TransLangs\Database\Repository\WpDbBoardQueries;
use TransLangs\Shared\Source\Type\HtmlSource;
use TransLangs\Shared\Source\Type\PostSource;
use TransLangs\Shared\Source\SourceRef;
use TransLangs\Shared\Source\TranslationStatus;
use TransLangs\Shared\Text\TL_Key;
use TransLangs\Database\TranslationMemorySchema;
use TransLangs\Language\Infrastructure\LanguageMetadataStore;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use TransLangs\Resolution\PageOwnedHashes;
use TransLangs\Resolution\StructuredRenderResolver;

global $wpdb;

tl_guard();
tl_begin();

$repo     = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();
$parents  = TranslationMemorySchema::parentTables();
$PP       = $parents['tl_post'];

$default = $registry->getDefaultLanguage();
tl_ok('default/source language is set up', $default !== '', "default=$default");

// Two throwaway codes — not real WordPress locales, so they cannot collide with
// anything already configured on this dev site. qq_QQ holds the real
// translation; zz_ZZ is the "requested" language, translated for nothing, and
// falls back to qq_QQ.
$store->save(['code' => 'qq_QQ', 'fallback_language' => '']);
$store->save(['code' => 'zz_ZZ', 'fallback_language' => 'qq_QQ']);

// ── 1. fallbackChain() against the real options table ────────────────────────

tl_section('fallbackChain (live options):');

tl_ok(
    'one hop -> [qq_QQ]',
    $registry->fallbackChain('zz_ZZ') === ['qq_QQ'],
    'got [' . implode(', ', $registry->fallbackChain('zz_ZZ')) . ']'
);

$store->save(['code' => 'qq_QQ', 'fallback_language' => 'zz_ZZ']); // qq_QQ -> zz_ZZ -> qq_QQ
tl_ok(
    'a configured cycle stops instead of looping forever',
    $registry->fallbackChain('zz_ZZ') === ['qq_QQ'],
    'got [' . implode(', ', $registry->fallbackChain('zz_ZZ')) . ']'
);
$store->save(['code' => 'qq_QQ', 'fallback_language' => '']); // restore for section 2/3 below

// ── 2. Post title: StructuredRenderResolver's real merge algorithm ──────────

tl_section('StructuredRenderResolver::ensureHashLoaded (post title):');

$now    = current_time('mysql');
$postId = tl_insert($wpdb->posts, [
    'post_author'       => 1,
    'post_date'         => $now,
    'post_date_gmt'     => $now,
    'post_content'      => '',
    'post_title'        => 'TLTEST Fallback Title',
    'post_excerpt'      => '',
    'post_status'       => 'publish',
    'comment_status'    => 'closed',
    'ping_status'       => 'closed',
    'post_name'         => 'tltest-fallback-title',
    'post_modified'     => $now,
    'post_modified_gmt' => $now,
    'post_type'         => 'post',
]);
tl_ok('fixture post created', $postId > 0, "post_id=$postId");

$repo->upsertSources([new PostSource($postId, 'post_title', 'TLTEST Fallback Title')]);
$discovered = TranslationStatus::SOURCE_DISCOVERED;
$titleId    = (int) tl_var(
    "SELECT id FROM {$PP} WHERE post_id = %d AND field = %s AND original_hash = UNHEX(%s) AND status = %d",
    $postId,
    'post_title',
    strtolower(bin2hex(TL_Key::currentHash('TLTEST Fallback Title'))),
    $discovered
);
tl_ok('title source row found', $titleId > 0, "id=$titleId");

// Translated only in qq_QQ — zz_ZZ (the "requested" language) has no row at all.
$repo->saveTranslation(SourceRef::make('post', $titleId), 'qq_QQ', 'Titre TLTEST QQ', TranslationStatus::MACHINE, 'test');

$hash     = TL_Key::currentHash('TLTEST Fallback Title');
$resolver = (new ReflectionClass(StructuredRenderResolver::class))->newInstanceWithoutConstructor();

$setProp = static function (string $prop, $value) use ($resolver): void {
    $p = new ReflectionProperty(StructuredRenderResolver::class, $prop);
    $p->setAccessible(true);
    $p->setValue($resolver, $value);
};
$setProp('repository', $repo);
$setProp('pageOwned', new PageOwnedHashes());
$setProp('langsToTry', ['zz_ZZ', 'qq_QQ']); // what isActive() would compute for a zz_ZZ request

$ensureHashLoaded = new ReflectionMethod(StructuredRenderResolver::class, 'ensureHashLoaded');
$ensureHashLoaded->setAccessible(true);
$ensureHashLoaded->invoke($resolver, 'tl_post', [$hash]);

$hashMapProp = new ReflectionProperty(StructuredRenderResolver::class, 'hashMap');
$hashMapProp->setAccessible(true);
$hashMap = $hashMapProp->getValue($resolver);

tl_ok(
    "requested language misses, fallback language's translation is used",
    ($hashMap['tl_post|' . bin2hex($hash)] ?? null) === 'Titre TLTEST QQ',
    'got ' . json_encode($hashMap)
);

// ── 3. Hardcoded HTML: the simpler tl_html/tl_option "+=" merge ─────────────
// Same pattern PublicRenderPipeline::pageOutputTranslations() and
// OptionResolver::map() use — verified directly against the repository since
// there is no private algorithm here beyond a plain array union.

tl_section('tl_html fallback merge (PublicRenderPipeline/OptionResolver pattern):');

$repo->upsertSources([new HtmlSource('.tltest-fallback-block', null, 'TLTEST Fallback Html')]);
$htmlTable = $parents['tl_html'];
$htmlId    = (int) tl_var(
    "SELECT id FROM {$htmlTable} WHERE original_hash = UNHEX(%s) AND status = %d",
    strtolower(bin2hex(TL_Key::currentHash('TLTEST Fallback Html'))),
    $discovered
);
tl_ok('html source row found', $htmlId > 0, "id=$htmlId");
$repo->saveTranslation(SourceRef::make('html', $htmlId), 'qq_QQ', 'HTML TLTEST QQ', TranslationStatus::MACHINE, 'test');

$map = [];
foreach (array_merge(['zz_ZZ'], $registry->fallbackChain('zz_ZZ')) as $lang) {
    $map += $repo->fetchAllHashTranslations('tl_html', $lang) + $repo->fetchAllHashTranslations('tl_option', $lang);
}
$htmlHashHex = strtolower(bin2hex(TL_Key::currentHash('TLTEST Fallback Html')));
tl_ok(
    "requested language misses, fallback language's html translation is used",
    ($map[$htmlHashHex] ?? null) === 'HTML TLTEST QQ',
    'got ' . json_encode($map[$htmlHashHex] ?? null)
);

// ── 4. REST write guard ──────────────────────────────────────────────────────

tl_section('LanguageRestController fallback_language guard:');

$controller = new LanguageRestController($store, $registry, $repo);

$selfRef = new WP_REST_Request('PATCH', '/translangs/v1/languages/qq_QQ');
$selfRef->set_url_params(['code' => 'qq_QQ']);
$selfRef->set_param('fallback_language', 'qq_QQ');
$res = $controller->update($selfRef);
tl_ok('self-reference rejected', ($res->get_data()['error'] ?? null) === 'ERR_INVALID_INPUT', json_encode($res->get_data()));

// xx_XX is a well-formed locale shape (2-letter language + 2-letter region, so
// LocaleNormalizer::normalize() passes it through unchanged) that was simply
// never added as a language in this test — unlike a malformed string, which
// normalize() would reduce to '' (a legitimately valid "no fallback" value).
$unknown = new WP_REST_Request('PATCH', '/translangs/v1/languages/qq_QQ');
$unknown->set_url_params(['code' => 'qq_QQ']);
$unknown->set_param('fallback_language', 'xx_XX');
$res = $controller->update($unknown);
tl_ok('unknown/never-added code rejected', ($res->get_data()['error'] ?? null) === 'ERR_INVALID_INPUT', json_encode($res->get_data()));

$valid = new WP_REST_Request('PATCH', '/translangs/v1/languages/qq_QQ');
$valid->set_url_params(['code' => 'qq_QQ']);
$valid->set_param('fallback_language', $default);
$res = $controller->update($valid);
tl_ok('the default language is accepted as a fallback', ($res->get_data()['ok'] ?? false) === true, json_encode($res->get_data()));

// ── 5. Admin board: CollectionBoard::collectionTexts() 'fallback' field ─────
// Reuses the .tltest-fallback-block fixture from section 3: zz_ZZ has no
// translation for it at all, and its chain is zz_ZZ -> qq_QQ -> (default) —
// the board's 'fallback' field should surface qq_QQ's translation, not the
// raw source text it used to hardcode.

tl_section('CollectionBoard fallback field (admin board):');

$board    = new CollectionBoard($repo, $registry);
$boardRows = $board->collectionTexts('html:all', 'zz_ZZ', '') ?? [];
$boardRow  = null;
foreach ($boardRows as $r) {
    if ($r['id'] === SourceRef::make('html', $htmlId)->format()) {
        $boardRow = $r;
        break;
    }
}
tl_ok('fixture html row found in zz_ZZ board', $boardRow !== null, 'row=' . json_encode($boardRow));
tl_ok(
    "board 'fallback' field resolves through the chain, not the raw source text",
    ($boardRow['fallback'] ?? null) === 'HTML TLTEST QQ',
    'got ' . json_encode($boardRow['fallback'] ?? null)
);

// ── 6. "Use fallback translation": LanguageRestController::useFallback() ────
// A dedicated same-base-language pair (zz_AA -> zz_BB), independent of the
// cross-language zz_ZZ/qq_QQ pair above — useFallback() must accept this one
// and reject that one.

tl_section('useFallback() bulk-copy:');

$store->save(['code' => 'zz_AA', 'fallback_language' => '']);
$store->save(['code' => 'zz_BB', 'fallback_language' => 'zz_AA']);

$repo->upsertSources([new HtmlSource('.tltest-usefallback-block', null, 'TLTEST UseFallback Html')]);
$ufHashHex = strtolower(bin2hex(TL_Key::currentHash('TLTEST UseFallback Html')));
$ufId      = (int) tl_var(
    "SELECT id FROM {$htmlTable} WHERE original_hash = UNHEX(%s) AND status = %d",
    $ufHashHex,
    $discovered
);
tl_ok('use-fallback fixture html row found', $ufId > 0, "id=$ufId");
$repo->saveTranslation(SourceRef::make('html', $ufId), 'zz_AA', 'UseFallback TLTEST AA', TranslationStatus::MACHINE, 'test');

tl_ok(
    'hasFallbackTranslationsToCopy() sees the new fixture before copying',
    $repo->hasFallbackTranslationsToCopy('zz_AA', 'zz_BB') === true,
    'expected true'
);

$indexBefore   = $controller->index()->get_data();
$zzBbBefore    = null;
foreach ($indexBefore['languages'] as $l) {
    if ($l['code'] === 'zz_BB') {
        $zzBbBefore = $l;
        break;
    }
}
tl_ok(
    "index()'s fallback_ready is true before copying",
    ($zzBbBefore['fallback_ready'] ?? null) === true,
    json_encode($zzBbBefore)
);

$useFallbackReq = new WP_REST_Request('POST', '/translangs/v1/languages/zz_BB/use-fallback');
$useFallbackReq->set_url_params(['code' => 'zz_BB']);
$res = $controller->useFallback($useFallbackReq);
tl_ok(
    'same-base-language fallback copies at least the new fixture',
    ($res->get_data()['ok'] ?? false) === true && ($res->get_data()['copied'] ?? 0) >= 1,
    json_encode($res->get_data())
);

$copiedText = $repo->fetchHashTranslations('tl_html', [TL_Key::currentHash('TLTEST UseFallback Html')], 'zz_BB')[$ufHashHex] ?? null;
tl_ok('zz_BB now has zz_AA\'s translation', $copiedText === 'UseFallback TLTEST AA', 'got ' . json_encode($copiedText));

$childTable = $htmlTable . '_translation';
$copiedStatus = (int) tl_var(
    "SELECT status FROM {$childTable} WHERE lang_code = %s AND original_hash = UNHEX(%s)",
    'zz_BB',
    $ufHashHex
);
tl_ok('copied translation is marked needs_review', $copiedStatus === TranslationStatus::NEEDS_REVIEW, "status=$copiedStatus");

$resAgain = $controller->useFallback($useFallbackReq);
tl_ok(
    're-running is a no-op (INSERT IGNORE, no duplicate)',
    ($resAgain->get_data()['ok'] ?? false) === true && ($resAgain->get_data()['copied'] ?? -1) === 0,
    json_encode($resAgain->get_data())
);

tl_ok(
    'hasFallbackTranslationsToCopy() is false once everything is copied',
    $repo->hasFallbackTranslationsToCopy('zz_AA', 'zz_BB') === false,
    'expected false'
);

$indexAfter = $controller->index()->get_data();
$zzBbAfter  = null;
foreach ($indexAfter['languages'] as $l) {
    if ($l['code'] === 'zz_BB') {
        $zzBbAfter = $l;
        break;
    }
}
tl_ok(
    "index()'s fallback_ready flips to false with nothing left to copy — this is what disables the rail button",
    ($zzBbAfter['fallback_ready'] ?? null) === false,
    json_encode($zzBbAfter)
);

$crossLangReq = new WP_REST_Request('POST', '/translangs/v1/languages/zz_ZZ/use-fallback');
$crossLangReq->set_url_params(['code' => 'zz_ZZ']);
$crossLangRes = $controller->useFallback($crossLangReq);
tl_ok(
    "a cross-language fallback (zz_ZZ -> qq_QQ) is rejected server-side, even though it's a valid fallback_language value",
    ($crossLangRes->get_data()['error'] ?? null) === 'ERR_NO_FALLBACK',
    json_encode($crossLangRes->get_data())
);

tl_done();

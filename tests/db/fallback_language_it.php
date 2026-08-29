<?php

/**
 * Fallback-language feature end to end:
 *   1. OptionLanguageRegistry::fallbackChain() against the real options table.
 *   2. StructuredRenderResolver's real merge algorithm (post title), reached by
 *      reflection into the shipped private ensureHashLoaded() — same technique
 *      as tests/option_gate_check.php — so this proves the actual production
 *      code resolves the chain, not a re-implementation of it.
 *   3. The simpler html/option "+=" merge used by
 *      PublicRenderPipeline::pageOutputTranslations() and OptionResolver::map().
 *   4. LanguageRestController's fallback_language write guard.
 *   5. CollectionBoard::collectionTexts()'s 'fallback' field — the admin board
 *      preview, which used to hardcode the raw source text regardless of
 *      fallback_language.
 *
 * Run: wp eval-file tests/db/fallback_language_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Board\CollectionBoard;
use LingoWP\Language\LanguageRestController;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Database\Repository\WpDbBoardQueries;
use LingoWP\Shared\Source\Type\HtmlSource;
use LingoWP\Shared\Source\Type\PostSource;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Shared\Text\TranslationKey;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Resolution\PageOwnedHashes;
use LingoWP\Resolution\StructuredRenderResolver;

global $wpdb;

test_guard();
test_begin();

$repo     = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();
$parents  = TranslationMemorySchema::parentTables();
$PP       = $parents['post'];

$default = $registry->getDefaultLanguage();
test_ok('default/source language is set up', $default !== '', "default=$default");

// Two throwaway codes — not real WordPress locales, so they cannot collide with
// anything already configured on this dev site. qq_QQ holds the real
// translation; zz_ZZ is the "requested" language, translated for nothing, and
// falls back to qq_QQ.
$store->save(['code' => 'qq_QQ', 'fallback_language' => '']);
$store->save(['code' => 'zz_ZZ', 'fallback_language' => 'qq_QQ']);

// ── 1. fallbackChain() against the real options table ────────────────────────

test_section('fallbackChain (live options):');

test_ok(
    'one hop -> [qq_QQ]',
    $registry->fallbackChain('zz_ZZ') === ['qq_QQ'],
    'got [' . implode(', ', $registry->fallbackChain('zz_ZZ')) . ']'
);

$store->save(['code' => 'qq_QQ', 'fallback_language' => 'zz_ZZ']); // qq_QQ -> zz_ZZ -> qq_QQ
test_ok(
    'a configured cycle stops instead of looping forever',
    $registry->fallbackChain('zz_ZZ') === ['qq_QQ'],
    'got [' . implode(', ', $registry->fallbackChain('zz_ZZ')) . ']'
);
$store->save(['code' => 'qq_QQ', 'fallback_language' => '']); // restore for section 2/3 below

// ── 2. Post title: StructuredRenderResolver's real merge algorithm ──────────

test_section('StructuredRenderResolver::ensureHashLoaded (post title):');

$now    = current_time('mysql');
$postId = test_insert($wpdb->posts, [
    'post_author'       => 1,
    'post_date'         => $now,
    'post_date_gmt'     => $now,
    'post_content'      => '',
    'post_title'        => 'LINGOWP_TEST Fallback Title',
    'post_excerpt'      => '',
    'post_status'       => 'publish',
    'comment_status'    => 'closed',
    'ping_status'       => 'closed',
    'post_name'         => 'lingowp-test-fallback-title',
    'post_modified'     => $now,
    'post_modified_gmt' => $now,
    'post_type'         => 'post',
]);
test_ok('fixture post created', $postId > 0, "post_id=$postId");

$repo->upsertSources([new PostSource($postId, 'post_title', 'LINGOWP_TEST Fallback Title')]);
$discovered = TranslationStatus::SOURCE_DISCOVERED;
$titleId    = (int) test_var(
    "SELECT id FROM {$PP} WHERE post_id = %d AND field = %s AND original_hash = UNHEX(%s) AND status = %d",
    $postId,
    'post_title',
    strtolower(bin2hex(TranslationKey::currentHash('LINGOWP_TEST Fallback Title'))),
    $discovered
);
test_ok('title source row found', $titleId > 0, "id=$titleId");

// Translated only in qq_QQ — zz_ZZ (the "requested" language) has no row at all.
$repo->saveTranslation(SourceRef::make('post', $titleId), 'qq_QQ', 'Titre LINGOWP_TEST QQ', TranslationStatus::MACHINE, 'test');

$hash     = TranslationKey::currentHash('LINGOWP_TEST Fallback Title');
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
$ensureHashLoaded->invoke($resolver, 'post', [$hash]);

$hashMapProp = new ReflectionProperty(StructuredRenderResolver::class, 'hashMap');
$hashMapProp->setAccessible(true);
$hashMap = $hashMapProp->getValue($resolver);

test_ok(
    "requested language misses, fallback language's translation is used",
    ($hashMap['post|' . bin2hex($hash)] ?? null) === 'Titre LINGOWP_TEST QQ',
    'got ' . json_encode($hashMap)
);

// ── 3. Hardcoded HTML: the simpler html/option "+=" merge ─────────────
// Same pattern PublicRenderPipeline::pageOutputTranslations() and
// OptionResolver::map() use — verified directly against the repository since
// there is no private algorithm here beyond a plain array union.

test_section('html fallback merge (PublicRenderPipeline/OptionResolver pattern):');

$repo->upsertSources([new HtmlSource('.lingowp-test-fallback-block', null, 'LINGOWP_TEST Fallback Html')]);
$htmlTable = $parents['html'];
$htmlId    = (int) test_var(
    "SELECT id FROM {$htmlTable} WHERE original_hash = UNHEX(%s) AND status = %d",
    strtolower(bin2hex(TranslationKey::currentHash('LINGOWP_TEST Fallback Html'))),
    $discovered
);
test_ok('html source row found', $htmlId > 0, "id=$htmlId");
$repo->saveTranslation(SourceRef::make('html', $htmlId), 'qq_QQ', 'HTML LINGOWP_TEST QQ', TranslationStatus::MACHINE, 'test');

$map = [];
foreach (array_merge(['zz_ZZ'], $registry->fallbackChain('zz_ZZ')) as $lang) {
    $map += $repo->fetchAllHashTranslations('html', $lang) + $repo->fetchAllHashTranslations('option', $lang);
}
$htmlHashHex = strtolower(bin2hex(TranslationKey::currentHash('LINGOWP_TEST Fallback Html')));
test_ok(
    "requested language misses, fallback language's html translation is used",
    ($map[$htmlHashHex] ?? null) === 'HTML LINGOWP_TEST QQ',
    'got ' . json_encode($map[$htmlHashHex] ?? null)
);

// ── 4. REST write guard ──────────────────────────────────────────────────────

test_section('LanguageRestController fallback_language guard:');

$controller = new LanguageRestController($store, $registry, $repo);

$selfRef = new WP_REST_Request('PATCH', '/lingowp/v1/languages/qq_QQ');
$selfRef->set_url_params(['code' => 'qq_QQ']);
$selfRef->set_param('fallback_language', 'qq_QQ');
$res = $controller->update($selfRef);
test_ok('self-reference rejected', ($res->get_data()['error'] ?? null) === 'ERR_INVALID_INPUT', json_encode($res->get_data()));

// xx_XX is a well-formed locale shape (2-letter language + 2-letter region, so
// LocaleNormalizer::normalize() passes it through unchanged) that was simply
// never added as a language in this test — unlike a malformed string, which
// normalize() would reduce to '' (a legitimately valid "no fallback" value).
$unknown = new WP_REST_Request('PATCH', '/lingowp/v1/languages/qq_QQ');
$unknown->set_url_params(['code' => 'qq_QQ']);
$unknown->set_param('fallback_language', 'xx_XX');
$res = $controller->update($unknown);
test_ok('unknown/never-added code rejected', ($res->get_data()['error'] ?? null) === 'ERR_INVALID_INPUT', json_encode($res->get_data()));

$valid = new WP_REST_Request('PATCH', '/lingowp/v1/languages/qq_QQ');
$valid->set_url_params(['code' => 'qq_QQ']);
$valid->set_param('fallback_language', $default);
$res = $controller->update($valid);
test_ok('the default language is accepted as a fallback', ($res->get_data()['ok'] ?? false) === true, json_encode($res->get_data()));

// ── 5. Admin board: CollectionBoard::collectionTexts() 'fallback' field ─────
// Reuses the .lingowp-test-fallback-block fixture from section 3: zz_ZZ has no
// translation for it at all, and its chain is zz_ZZ -> qq_QQ -> (default) —
// the board's 'fallback' field should surface qq_QQ's translation, not the
// raw source text it used to hardcode.

test_section('CollectionBoard fallback field (admin board):');

$board    = new CollectionBoard($repo, $registry);
$boardRows = $board->collectionTexts('html:all', 'zz_ZZ', '') ?? [];
$boardRow  = null;
foreach ($boardRows as $r) {
    if ($r['id'] === SourceRef::make('html', $htmlId)->format()) {
        $boardRow = $r;
        break;
    }
}
test_ok('fixture html row found in zz_ZZ board', $boardRow !== null, 'row=' . json_encode($boardRow));
test_ok(
    "board 'fallback' field resolves through the chain, not the raw source text",
    ($boardRow['fallback'] ?? null) === 'HTML LINGOWP_TEST QQ',
    'got ' . json_encode($boardRow['fallback'] ?? null)
);

// ── 6. "Use fallback translation": LanguageRestController::useFallback() ────
// A dedicated same-base-language pair (zz_AA -> zz_BB), independent of the
// cross-language zz_ZZ/qq_QQ pair above — useFallback() must accept this one
// and reject that one.

test_section('useFallback() bulk-copy:');

$store->save(['code' => 'zz_AA', 'fallback_language' => '']);
$store->save(['code' => 'zz_BB', 'fallback_language' => 'zz_AA']);

$repo->upsertSources([new HtmlSource('.lingowp-test-usefallback-block', null, 'LINGOWP_TEST UseFallback Html')]);
$ufHashHex = strtolower(bin2hex(TranslationKey::currentHash('LINGOWP_TEST UseFallback Html')));
$ufId      = (int) test_var(
    "SELECT id FROM {$htmlTable} WHERE original_hash = UNHEX(%s) AND status = %d",
    $ufHashHex,
    $discovered
);
test_ok('use-fallback fixture html row found', $ufId > 0, "id=$ufId");
$repo->saveTranslation(SourceRef::make('html', $ufId), 'zz_AA', 'UseFallback LINGOWP_TEST AA', TranslationStatus::MACHINE, 'test');

test_ok(
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
test_ok(
    "index()'s fallback_ready is true before copying",
    ($zzBbBefore['fallback_ready'] ?? null) === true,
    json_encode($zzBbBefore)
);

$useFallbackReq = new WP_REST_Request('POST', '/lingowp/v1/languages/zz_BB/use-fallback');
$useFallbackReq->set_url_params(['code' => 'zz_BB']);
$res = $controller->useFallback($useFallbackReq);
test_ok(
    'same-base-language fallback copies at least the new fixture',
    ($res->get_data()['ok'] ?? false) === true && ($res->get_data()['copied'] ?? 0) >= 1,
    json_encode($res->get_data())
);

$copiedText = $repo->fetchHashTranslations('html', [TranslationKey::currentHash('LINGOWP_TEST UseFallback Html')], 'zz_BB')[$ufHashHex] ?? null;
test_ok('zz_BB now has zz_AA\'s translation', $copiedText === 'UseFallback LINGOWP_TEST AA', 'got ' . json_encode($copiedText));

$childTable = $htmlTable . '_translation';
$copiedStatus = (int) test_var(
    "SELECT status FROM {$childTable} WHERE lang_code = %s AND original_hash = UNHEX(%s)",
    'zz_BB',
    $ufHashHex
);
test_ok('copied translation is marked needs_review', $copiedStatus === TranslationStatus::NEEDS_REVIEW, "status=$copiedStatus");

$resAgain = $controller->useFallback($useFallbackReq);
test_ok(
    're-running is a no-op (INSERT IGNORE, no duplicate)',
    ($resAgain->get_data()['ok'] ?? false) === true && ($resAgain->get_data()['copied'] ?? -1) === 0,
    json_encode($resAgain->get_data())
);

test_ok(
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
test_ok(
    "index()'s fallback_ready flips to false with nothing left to copy — this is what disables the rail button",
    ($zzBbAfter['fallback_ready'] ?? null) === false,
    json_encode($zzBbAfter)
);

$crossLangReq = new WP_REST_Request('POST', '/lingowp/v1/languages/zz_ZZ/use-fallback');
$crossLangReq->set_url_params(['code' => 'zz_ZZ']);
$crossLangRes = $controller->useFallback($crossLangReq);
test_ok(
    "a cross-language fallback (zz_ZZ -> qq_QQ) is rejected server-side, even though it's a valid fallback_language value",
    ($crossLangRes->get_data()['error'] ?? null) === 'ERR_NO_FALLBACK',
    json_encode($crossLangRes->get_data())
);

test_done();

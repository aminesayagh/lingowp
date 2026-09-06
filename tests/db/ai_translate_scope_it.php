<?php

/**
 * AiScopeUnits: what "Translate All" and per-collection "Translate all" actually
 * resolve, against real tables. Run: wp eval-file tests/db/ai_translate_scope_it.php
 *
 * Three regressions this pins shut:
 *   1. The language scope used to miss author bios and term meta entirely — those
 *      texts were never queued and the language % could never reach 100.
 *   2. The collection scope returned null (→ 400 ERR_INVALID_INPUT → "AI
 *      translation failed") for user:bios and media:all.
 *   3. Resolving the language scope was an N+1 over every post and term. The
 *      query-count assertion at the end is what keeps it flat.
 *
 * Nothing here submits: AiScopeUnits only reads.
 */

require __DIR__ . '/_harness.php';

use LingoWP\AiTranslation\AiScopeUnits;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Database\Repository\WpDbBoardQueries;
use LingoWP\Shared\Source\Type\PostSource;
use LingoWP\Shared\Source\Type\PostMetaSource;
use LingoWP\Shared\Source\Type\TermSource;
use LingoWP\Shared\Source\Type\TermMetaSource;
use LingoWP\Shared\Source\Type\UserSource;
use LingoWP\Shared\Source\Type\OptionSource;
use LingoWP\Shared\Source\Type\HtmlSource;

global $wpdb;

test_guard();
test_begin();

$repo  = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
$units = new AiScopeUnits($repo);
$LANG  = 'fr';
$TAX   = 'lingowp-test_tax';

// ── Fixtures ───────────────────────────────────────────────────────────────

$now = current_time('mysql');
$makePost = static function (string $title, string $slug, string $status, string $type = 'post', string $mime = '') use ($wpdb, $now): int {
    return test_insert($wpdb->posts, [
        'post_author'       => 1,
        'post_date'         => $now,
        'post_date_gmt'     => $now,
        'post_content'      => '',
        'post_title'        => $title,
        'post_excerpt'      => '',
        'post_status'       => $status,
        'comment_status'    => 'closed',
        'ping_status'       => 'closed',
        'post_name'         => $slug,
        'post_modified'     => $now,
        'post_modified_gmt' => $now,
        'post_type'         => $type,
        'post_mime_type'    => $mime,
    ]);
};

$liveId    = $makePost('LINGOWP_TEST Scope Live', 'lingowp-test-scope-live', 'publish');
$trashedId = $makePost('LINGOWP_TEST Scope Trashed', 'lingowp-test-scope-trashed', 'trash');
$mediaId   = $makePost('LINGOWP_TEST Scope Image', 'lingowp-test-scope-image', 'inherit', 'attachment', 'image/png');
test_ok('fixture posts created', $liveId > 0 && $trashedId > 0 && $mediaId > 0, "live=$liveId trashed=$trashedId media=$mediaId");

$termId = test_insert($wpdb->terms, ['name' => 'LINGOWP_TEST Scope Term', 'slug' => 'lingowp-test-scope-term', 'term_group' => 0]);
$ttId   = test_insert($wpdb->term_taxonomy, [
    'term_id' => $termId, 'taxonomy' => $TAX, 'description' => '', 'parent' => 0, 'count' => 0,
]);
test_ok('fixture term created', $ttId > 0, "term_taxonomy_id=$ttId");

$userId = (int) test_var("SELECT ID FROM {$wpdb->users} ORDER BY ID LIMIT 1");
test_ok('a user exists to hang a bio on', $userId > 0, "user_id=$userId");

$optionId = (int) test_var("SELECT option_id FROM {$wpdb->options} WHERE option_name = 'blogname'");
test_ok('blogname has a wp_options row', $optionId > 0, "option_id=$optionId");

$repo->upsertSources([
    new PostSource($liveId, 'post_title', 'LINGOWP_TEST Scope Live Title'),
    new PostMetaSource($liveId, '_lingowp_test_scope_meta', null, 'LINGOWP_TEST Scope Post Meta'),
    new PostSource($trashedId, 'post_title', 'LINGOWP_TEST Scope Trashed Title'),
    new PostSource($mediaId, 'post_title', 'LINGOWP_TEST Scope Image Title'),
    new PostMetaSource($mediaId, '_wp_attachment_image_alt', null, 'LINGOWP_TEST Scope Alt Text'),
    new TermSource($ttId, 'name', 'LINGOWP_TEST Scope Term Name'),
    new TermMetaSource($termId, '_lingowp_test_scope_termmeta', null, 'LINGOWP_TEST Scope Term Meta'),
    new UserSource($userId, 'description', 'LINGOWP_TEST Scope Author Bio'),
    new OptionSource($optionId, 'blogname', '', 'LINGOWP_TEST Scope Site Name'),
    new HtmlSource('.lingowp-test-scope', 'https://example.test/scope', 'LINGOWP_TEST Scope Html'),
]);

/** @return array<string,int> kind => count, keyed off each unit's SourceRef */
$byKind = static function (array $resolved): array {
    $out = [];
    foreach ($resolved as $u) {
        $kind = $u['ref']->kind;
        $out[$kind] = ($out[$kind] ?? 0) + 1;
    }
    return $out;
};

/** Every original_text in a resolved set, for membership assertions. */
$originals = static fn (array $resolved): array => array_map(
    static fn (array $u): string => $u['original'],
    $resolved
);

// ── 1. Language scope covers every source kind ─────────────────────────────

test_section('language scope coverage:');

$lang = $units->forScope('language', $LANG);
test_ok('language scope resolves', is_array($lang), gettype($lang));

$kinds = $byKind($lang);
foreach (['post', 'meta', 'term', 'term_meta', 'user', 'option', 'html'] as $kind) {
    test_ok("language scope includes '{$kind}' units", ($kinds[$kind] ?? 0) > 0, 'counts: ' . json_encode($kinds));
}

$langTexts = $originals($lang);
test_ok('author bio is queued', in_array('LINGOWP_TEST Scope Author Bio', $langTexts, true));
test_ok('term meta is queued', in_array('LINGOWP_TEST Scope Term Meta', $langTexts, true));
test_ok('attachment alt text is queued', in_array('LINGOWP_TEST Scope Alt Text', $langTexts, true));

// The post_status guard is load-bearing: a trashed post is not on the board, so
// its text must never reach the AI queue either.
test_ok('trashed post text is NOT queued',
    ! in_array('LINGOWP_TEST Scope Trashed Title', $langTexts, true));

// ── 2. Unit shape: group_id and meta_key ───────────────────────────────────

test_section('unit shape:');

$blankGroup = array_filter($lang, static fn (array $u): bool => ($u['group_id'] ?? '') === '');
test_ok('every unit carries a group_id', $blankGroup === [], count($blankGroup) . ' unit(s) without one');

$groupOf = static function (array $resolved, string $text): string {
    foreach ($resolved as $u) {
        if ($u['original'] === $text) {
            return (string) $u['group_id'];
        }
    }
    return '';
};
test_ok('post unit groups by its own post id',
    $groupOf($lang, 'LINGOWP_TEST Scope Live Title') === "content:{$liveId}",
    $groupOf($lang, 'LINGOWP_TEST Scope Live Title'));
test_ok('post-meta unit groups by its own post id',
    $groupOf($lang, 'LINGOWP_TEST Scope Post Meta') === "content:{$liveId}",
    $groupOf($lang, 'LINGOWP_TEST Scope Post Meta'));
test_ok('term unit groups by its own term id',
    $groupOf($lang, 'LINGOWP_TEST Scope Term Name') === "term:{$termId}",
    $groupOf($lang, 'LINGOWP_TEST Scope Term Name'));
test_ok('author bio groups under user:bios',
    $groupOf($lang, 'LINGOWP_TEST Scope Author Bio') === 'user:bios',
    $groupOf($lang, 'LINGOWP_TEST Scope Author Bio'));

$metaKeyOf = static function (array $resolved, string $text): ?string {
    foreach ($resolved as $u) {
        if ($u['original'] === $text) {
            return $u['meta_key'];
        }
    }
    return null;
};
// TranslationSubmissionGuard picks its per-key grammar off this, for both
// parent_id kinds — term_meta used to come through with a null key.
test_ok('post-meta unit carries its meta_key',
    $metaKeyOf($lang, 'LINGOWP_TEST Scope Post Meta') === '_lingowp_test_scope_meta',
    var_export($metaKeyOf($lang, 'LINGOWP_TEST Scope Post Meta'), true));
test_ok('term-meta unit carries its meta_key',
    $metaKeyOf($lang, 'LINGOWP_TEST Scope Term Meta') === '_lingowp_test_scope_termmeta',
    var_export($metaKeyOf($lang, 'LINGOWP_TEST Scope Term Meta'), true));

// ── 3. Collection scope resolves every board id ────────────────────────────

test_section('collection scope:');

foreach (['option:site', 'user:bios', 'html:all', 'media:all', "content:{$liveId}", "taxonomy:{$TAX}"] as $id) {
    test_ok("collection '{$id}' resolves", is_array($units->forScope('collection', $LANG, $id)));
}
test_ok('an unknown collection id is still rejected',
    $units->forScope('collection', $LANG, 'nonsense:42') === null);
test_ok('an unknown scope is rejected',
    $units->forScope('elsewhere', $LANG) === null);

$bios = $units->forScope('collection', $LANG, 'user:bios');
test_ok('user:bios returns the bio', in_array('LINGOWP_TEST Scope Author Bio', $originals($bios), true));

$media = $units->forScope('collection', $LANG, 'media:all');
$mediaTexts = $originals($media);
test_ok('media:all returns the attachment title', in_array('LINGOWP_TEST Scope Image Title', $mediaTexts, true));
test_ok('media:all returns the alt text', in_array('LINGOWP_TEST Scope Alt Text', $mediaTexts, true));

$tax = $units->forScope('collection', $LANG, "taxonomy:{$TAX}");
$taxTexts = $originals($tax);
test_ok('taxonomy scope returns the term name', in_array('LINGOWP_TEST Scope Term Name', $taxTexts, true));
test_ok('taxonomy scope returns the term meta', in_array('LINGOWP_TEST Scope Term Meta', $taxTexts, true));

// ── 4. The language scope stays flat in post/term count ────────────────────

test_section('query count:');

$before = $wpdb->num_queries;
$units->forScope('language', $LANG);
$baseline = $wpdb->num_queries - $before;

// Ten more posts and ten more terms, each with their own discovered text. Under
// the old per-post/per-term resolution this alone added ~30 queries.
$extra = [];
for ($i = 0; $i < 10; $i++) {
    $pid     = $makePost("LINGOWP_TEST Scope Bulk {$i}", "lingowp-test-scope-bulk-{$i}", 'publish');
    $extra[] = new PostSource($pid, 'post_title', "LINGOWP_TEST Scope Bulk Title {$i}");
    $extra[] = new PostMetaSource($pid, '_lingowp_test_scope_meta', null, "LINGOWP_TEST Scope Bulk Meta {$i}");

    $tid  = test_insert($wpdb->terms, ['name' => "LINGOWP_TEST Scope Bulk Term {$i}", 'slug' => "lingowp-test-scope-bulk-term-{$i}", 'term_group' => 0]);
    $ttid = test_insert($wpdb->term_taxonomy, [
        'term_id' => $tid, 'taxonomy' => $TAX, 'description' => '', 'parent' => 0, 'count' => 0,
    ]);
    $extra[] = new TermSource($ttid, 'name', "LINGOWP_TEST Scope Bulk Term Name {$i}");
}
$repo->upsertSources($extra);

$before = $wpdb->num_queries;
$grown  = $units->forScope('language', $LANG);
$after  = $wpdb->num_queries - $before;

test_ok('the 10 extra posts and terms were resolved',
    count($grown) >= count($lang) + 30, 'before=' . count($lang) . ' after=' . count($grown));
test_ok('query count did not grow with the extra posts/terms',
    $after === $baseline, "baseline={$baseline} grown={$after}");
test_ok('the language scope is a handful of queries, not an N+1',
    $after <= 20, "queries={$after}");

// ── 5. The rail's progress read: correct, and flat in language count ───────

test_section('languageProgressAll:');

$one  = $repo->languageProgress($LANG);
$many = $repo->languageProgressAll([$LANG, 'de', 'es']);

test_ok('batched total matches the per-language read',
    $many[$LANG]['total'] === $one['total'],
    "batched={$many[$LANG]['total']} single={$one['total']}");
test_ok('batched translated matches the per-language read',
    $many[$LANG]['translated'] === $one['translated'],
    "batched={$many[$LANG]['translated']} single={$one['translated']}");
test_ok('a language with no translations still gets a row',
    isset($many['de']) && $many['de']['translated'] === 0);
test_ok('every language sees the same source total',
    $many['de']['total'] === $many[$LANG]['total']);

// pending_texts is what makes the rail poll itself while a queue drains.
test_ok('nothing is queued yet', $many[$LANG]['pending'] === 0, (string) $many[$LANG]['pending']);

$queued = array_slice($units->forScope('language', $LANG), 0, 3);
$repo->markPendingBatch(array_map(static fn (array $u) => $u['ref'], $queued), [$LANG]);
$afterQueue = $repo->languageProgressAll([$LANG, 'de']);
test_ok('queued rows are counted as pending',
    $afterQueue[$LANG]['pending'] === count($queued),
    "pending={$afterQueue[$LANG]['pending']} expected=" . count($queued));
test_ok('another language is unaffected by the queue',
    $afterQueue['de']['pending'] === 0);

$before = $wpdb->num_queries;
$repo->languageProgressAll([$LANG, 'de']);
$twoLangs = $wpdb->num_queries - $before;

$before = $wpdb->num_queries;
$repo->languageProgressAll([$LANG, 'de', 'es', 'it', 'pt', 'nl', 'pl']);
$sevenLangs = $wpdb->num_queries - $before;

test_ok('progress query count does not grow with the language count',
    $sevenLangs === $twoLangs, "two={$twoLangs} seven={$sevenLangs}");

test_done();

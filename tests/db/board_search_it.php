<?php

/**
 * Board text search — the LIKE predicate threaded through the ten board reads.
 * Run: wp eval-file tests/db/board_search_it.php
 *
 * Covers: source-text match and translation match across the five board source
 * types, the LEFT JOIN invariant that keeps `missing` honest while searching,
 * empty-group elimination, esc_like literalness, collation case/accent folding,
 * and the known codec-marker limitation.
 *
 * Site-wide reads (listPostCollections, postMetaCounts, listTermCollections) are
 * asserted through row-picking helpers rather than array counts: the dev site has
 * its own content, and a search for a natural word may legitimately match it.
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Database\Repository\WpDbBoardQueries;
use LingoWP\Shared\Source\Type\HtmlSource;
use LingoWP\Shared\Source\Type\OptionSource;
use LingoWP\Shared\Source\Type\PostMetaSource;
use LingoWP\Shared\Source\Type\PostSource;
use LingoWP\Shared\Source\Type\TermSource;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Shared\Text\TranslationKey;
use LingoWP\Database\TranslationMemorySchema;

global $wpdb;

test_guard();
test_begin();

$repo    = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
$parents = TranslationMemorySchema::parentTables();
$PP      = $parents['post'];
$LANG    = 'fr';
$OTHER   = 'es';
$MISS    = 'zzzznothingxyz';

$now = current_time('mysql');

// ── 1. Fixtures ──────────────────────────────────────────────────────────────

test_section('fixtures:');

$makePost = static function (string $title, string $slug) use ($wpdb, $now): int {
    return test_insert($wpdb->posts, [
        'post_author'       => 1,
        'post_date'         => $now,
        'post_date_gmt'     => $now,
        'post_content'      => '',
        'post_title'        => $title,
        'post_excerpt'      => '',
        'post_status'       => 'publish',
        'comment_status'    => 'closed',
        'ping_status'       => 'closed',
        'post_name'         => $slug,
        'post_modified'     => $now,
        'post_modified_gmt' => $now,
        'post_type'         => 'post',
    ]);
};

$postId  = $makePost('LINGOWP_TEST Needle Page', 'lingowp-test-needle-page');
$postId2 = $makePost('LINGOWP_TEST Wildcard Post', 'lingowp-test-wildcard-post');
test_ok('fixture posts created', $postId > 0 && $postId2 > 0, "post_id=$postId, post_id2=$postId2");

// A real term row: fk_term_owner requires an existing term_taxonomy_id.
$termId = test_insert($wpdb->terms, ['name' => 'LINGOWP_TEST Term', 'slug' => 'lingowp-test-term', 'term_group' => 0]);
$ttId   = test_insert($wpdb->term_taxonomy, [
    'term_id'     => $termId,
    'taxonomy'    => 'lingowp-test_tax',
    'description' => '',
    'parent'      => 0,
    'count'       => 0,
]);
test_ok('fixture term created', $ttId > 0, "term_taxonomy_id=$ttId");

$optionId = (int) test_var("SELECT option_id FROM {$wpdb->options} WHERE option_name = 'blogname'");
test_ok('blogname has a wp_options row', $optionId > 0, "option_id=$optionId");

$repo->upsertSources([
    new PostSource($postId, 'post_title', 'LINGOWP_TEST Needle Page'),
    new PostSource($postId, 'post_content', 'The quick brown fox jumps'),
    new PostSource($postId, 'post_content', 'Read {1}the manual{/1} carefully'),
    new PostSource($postId, 'post_excerpt', 'A café in Paris'),
    new PostSource($postId2, 'post_title', 'Save 50% today'),
    new PostSource($postId2, 'post_excerpt', 'a_b marker'),
    new PostMetaSource($postId, '_yoast_wpseo_title', null, 'LINGOWP_TEST meta needle'),
    new OptionSource($optionId, 'blogname', '', 'LINGOWP_TEST Needle Site'),
    new HtmlSource('.lingowp-test-block', 'https://example.test/', 'LINGOWP_TEST html needle'),
    new TermSource($ttId, 'name', 'LINGOWP_TEST Needle Term'),
]);

$discovered = TranslationStatus::SOURCE_DISCOVERED;

$postSourceId = static fn(int $pid, string $field, string $text): int => (int) test_var(
    "SELECT id FROM {$PP} WHERE post_id = %d AND field = %s AND original_hash = UNHEX(%s) AND status = %d",
    $pid,
    $field,
    strtolower(bin2hex(TranslationKey::currentHash($text))),
    $discovered
);

$titleId = $postSourceId($postId, 'post_title', 'LINGOWP_TEST Needle Page');
test_ok('title source row found', $titleId > 0, "id=$titleId");

$htmlTable = TranslationMemorySchema::parentTables()['html'];
$htmlUrl   = test_var(
    "SELECT source_url FROM {$htmlTable} WHERE original_hash = UNHEX(%s)",
    strtolower(bin2hex(TranslationKey::currentHash('LINGOWP_TEST html needle')))
);
test_ok('HTML source URL stores no domain', $htmlUrl === '/', "source_url=$htmlUrl");

$wpdb->update($htmlTable, ['source_url' => 'https://legacy.example.test/old-path/'], [
    'original_hash' => TranslationKey::currentHash('LINGOWP_TEST html needle'),
]);
$repo->upsertSources([
    new HtmlSource('.lingowp-test-block', 'https://example.test/repaired-path/?page=2', 'LINGOWP_TEST html needle'),
]);
$repairedUrl = test_var(
    "SELECT source_url FROM {$htmlTable} WHERE original_hash = UNHEX(%s)",
    strtolower(bin2hex(TranslationKey::currentHash('LINGOWP_TEST html needle')))
);
test_ok('HTML rediscovery repairs a legacy absolute URL', $repairedUrl === '/repaired-path/?page=2', "source_url=$repairedUrl");

// Exactly one translated row; "brown fox" is deliberately left untranslated so §5
// can prove an untranslated original match survives the WHERE clause.
$repo->saveTranslation(
    SourceRef::make('post', $titleId),
    $LANG,
    'Page Aiguille LINGOWP_TEST',
    TranslationStatus::MACHINE,
    'test'
);
$titleRow = $repo->listPostTexts($postId, $LANG, 'Needle Page')[0] ?? [];
test_ok('fixture translation saved', ($titleRow['translated_text'] ?? null) === 'Page Aiguille LINGOWP_TEST');

/** The collection row for our fixture post, or null — the dev site has other posts. */
$postColl = static function (string $search) use ($repo, $LANG, $postId): ?array {
    foreach ($repo->listPostCollections($LANG, $search) as $c) {
        if ($c['post_id'] === $postId) {
            return $c;
        }
    }
    return null;
};

/** Same as $postColl, for the second fixture post. */
$post2Coll = static function (string $search) use ($repo, $LANG, $postId2): ?array {
    foreach ($repo->listPostCollections($LANG, $search) as $c) {
        if ($c['post_id'] === $postId2) {
            return $c;
        }
    }
    return null;
};

// ── 2. Empty search is a no-op ───────────────────────────────────────────────
// The single most valuable guard here: it proves the unsearched board still runs
// exactly the query it ran before search existed.

test_section('empty search is a no-op:');

test_ok('listPostCollections', $repo->listPostCollections($LANG) == $repo->listPostCollections($LANG, ''));
test_ok('postMetaCounts', $repo->postMetaCounts($LANG) == $repo->postMetaCounts($LANG, ''));
test_ok('listTermCollections', $repo->listTermCollections($LANG) == $repo->listTermCollections($LANG, ''));
test_ok('optionCollectionSummary', $repo->optionCollectionSummary($LANG) == $repo->optionCollectionSummary($LANG, ''));
test_ok('htmlCollectionSummary', $repo->htmlCollectionSummary($LANG) == $repo->htmlCollectionSummary($LANG, ''));
test_ok('listPostTexts', $repo->listPostTexts($postId, $LANG) == $repo->listPostTexts($postId, $LANG, ''));
test_ok('listMetaTexts', $repo->listMetaTexts($postId, $LANG) == $repo->listMetaTexts($postId, $LANG, ''));
test_ok('listTaxonomyTexts', $repo->listTaxonomyTexts('lingowp-test_tax', $LANG) == $repo->listTaxonomyTexts('lingowp-test_tax', $LANG, ''));
test_ok('listOptionTexts', $repo->listOptionTexts($LANG) == $repo->listOptionTexts($LANG, ''));
test_ok('listHtmlTexts', $repo->listHtmlTexts($LANG) == $repo->listHtmlTexts($LANG, ''));

// ── 3. Original-text match, per source type ──────────────────────────────────

test_section('original text matches, all five board types:');

test_ok('post', count($repo->listPostTexts($postId, $LANG, 'brown fox')) === 1);
test_ok('meta', count($repo->listMetaTexts($postId, $LANG, 'meta needle')) === 1);
test_ok('option', count($repo->listOptionTexts($LANG, 'LINGOWP_TEST Needle Site')) === 1);
test_ok('html', count($repo->listHtmlTexts($LANG, 'LINGOWP_TEST html needle')) === 1);
test_ok('term', count($repo->listTaxonomyTexts('lingowp-test_tax', $LANG, 'LINGOWP_TEST Needle Term')) === 1);

test_ok('a miss returns nothing', $repo->listPostTexts($postId, $LANG, $MISS) === []);

// ── 4. Translation match, and language scoping ───────────────────────────────

test_section('translated text matches, scoped to the language:');

test_ok('translation matches in its own language',
    count($repo->listPostTexts($postId, $LANG, 'Aiguille')) === 1);
test_ok('translation does NOT leak into another language',
    count($repo->listPostTexts($postId, $OTHER, 'Aiguille')) === 0);
test_ok('original still matches in another language',
    count($repo->listPostTexts($postId, $OTHER, 'brown fox')) === 1);

// ── 5. The LEFT JOIN invariant — the core of the design ──────────────────────
// The predicate sits in WHERE. For an untranslated row `t.translated_text LIKE …`
// is NULL, and NULL OR TRUE is TRUE, so a row whose ORIGINAL matches survives with
// t.* still NULL and SUM(t.id IS NULL) keeps counting it as missing. If the
// predicate ever moves into the ON clause, these four assertions fail.

test_section('LEFT JOIN invariant (counts describe the matching subset):');

$c = $postColl('brown fox');
test_ok('untranslated original match survives the WHERE', $c !== null);
test_ok('  total is the match count', $c !== null && $c['total'] === 1, 'total=' . ($c['total'] ?? 'n/a'));
test_ok('  counted as missing', $c !== null && $c['missing'] === 1, 'missing=' . ($c['missing'] ?? 'n/a'));
test_ok('  not counted as translated', $c !== null && $c['translated'] === 0);

$c2 = $postColl('Aiguille');
test_ok('translation-only match returns the collection', $c2 !== null);
test_ok('  total is the match count', $c2 !== null && $c2['total'] === 1, 'total=' . ($c2['total'] ?? 'n/a'));
test_ok('  counted as translated', $c2 !== null && $c2['translated'] === 1);
test_ok('  not counted as missing', $c2 !== null && $c2['missing'] === 0, 'missing=' . ($c2['missing'] ?? 'n/a'));

// "LINGOWP_TEST" is also a substring of the post's own title, so once board name search
// (§10 below) matches it, every one of the post's 4 post rows passes the WHERE —
// not just the title row that used to be the only content-level match. total 1 → 4;
// translated stays 1 (only the title row has a saved translation).
$c3 = $postColl('LINGOWP_TEST');
test_ok('a broader term widens the subset', $c3 !== null && $c3['total'] === 4 && $c3['translated'] === 1);

// ── 6. Groups with no hits disappear ─────────────────────────────────────────
// The GROUP BY emits no row for a collection with no matches, which is how the
// board hides empty groups with no filtering code of its own.

test_section('empty groups vanish:');

test_ok('no post collections', $repo->listPostCollections($LANG, $MISS) === []);
test_ok('no meta counts', $repo->postMetaCounts($LANG, $MISS) === []);
test_ok('no term collections', $repo->listTermCollections($LANG, $MISS) === []);
test_ok('option summary total is 0', $repo->optionCollectionSummary($LANG, $MISS)['total'] === 0);
test_ok('html summary total is 0', $repo->htmlCollectionSummary($LANG, $MISS)['total'] === 0);

// ── 7. esc_like — % and _ are literal, never wildcards ───────────────────────

test_section('esc_like literalness:');

test_ok('% matches literally', count($repo->listPostTexts($postId2, $LANG, '50%')) === 1);
test_ok('% is not a wildcard', count($repo->listPostTexts($postId2, $LANG, 'Save%today')) === 0);
test_ok('_ matches literally', count($repo->listPostTexts($postId2, $LANG, 'a_b')) === 1);
test_ok('_ is not a wildcard', count($repo->listPostTexts($postId2, $LANG, 'axb')) === 0);
test_ok('a lone backslash matches nothing', count($repo->listPostTexts($postId2, $LANG, '\\')) === 0);

// ── 8. Collation freebies (utf8mb4_unicode_520_ci) ───────────────────────────
// Case- and accent-insensitivity come from the collation, not from any code —
// these break silently if the table collation ever changes.

test_section('case and accent folding:');

test_ok('case-insensitive', count($repo->listPostTexts($postId, $LANG, 'BROWN FOX')) === 1);
test_ok('accent-insensitive (cafe → café)', count($repo->listPostTexts($postId, $LANG, 'cafe')) === 1);
test_ok('accented query matches too', count($repo->listPostTexts($postId, $LANG, 'café')) === 1);

// ── 9. Codec markers — pins the known limitation ─────────────────────────────
// Composed units store inline-tag markers. A phrase inside one segment matches; a
// phrase that CROSSES a marker boundary does not, because plain LIKE sees the
// markers. Fixing it needs REGEXP_REPLACE (MySQL 8+) or a shadow column (DDL).
// The second assertion documents the gap rather than hiding it — if it ever
// starts passing, the limitation is gone and this file should say so.

test_section('codec markers:');

test_ok('phrase inside one segment matches',
    count($repo->listPostTexts($postId, $LANG, 'the manual')) === 1);
test_ok('text before a marker matches',
    count($repo->listPostTexts($postId, $LANG, 'Read ')) === 1);
test_ok('KNOWN LIMIT: a phrase crossing a marker boundary does not match',
    count($repo->listPostTexts($postId, $LANG, 'Read the manual carefully')) === 0);

// ── 10. Board name search — post title/slug widen the match to the whole post ──
// wp.post_title/wp.post_name don't vary per row, so once they match, every row
// belonging to that post passes the WHERE — not just the one row whose own text
// happens to contain the term. Deliberate: found the page by name, now show
// everything on it (an ordinary content-only match still just filters to the
// matching rows, unchanged — see §5 above).

test_section('board name search (post title / slug widen the match):');

test_ok('slug-only match returns the full post via listPostCollections',
    ($c4 = $post2Coll('lingowp-test-wildcard-post')) !== null && $c4['total'] === 2,
    'total=' . ($c4['total'] ?? 'n/a'));
test_ok('slug-only match returns the full post via listPostTexts',
    count($repo->listPostTexts($postId2, $LANG, 'lingowp-test-wildcard-post')) === 2);
test_ok('non-prefix title substring also widens to the full post',
    count($repo->listPostTexts($postId, $LANG, 'Needle Page')) === 4);
test_ok('name match folds case too (not yet exercised against a wp_posts column)',
    count($repo->listPostTexts($postId, $LANG, 'needle page')) === 4);
test_ok('slug match also widens listMetaTexts',
    count($repo->listMetaTexts($postId, $LANG, 'lingowp-test-needle-page')) === 1);
$metaRow = null;
foreach ($repo->postMetaCounts($LANG, 'lingowp-test-needle-page') as $countRow) {
    if ($countRow['post_id'] === $postId) {
        $metaRow = $countRow;
    }
}
test_ok('slug match also widens postMetaCounts', $metaRow !== null && $metaRow['total'] === 1,
    'total=' . ($metaRow['total'] ?? 'n/a'));
test_ok('a genuine miss still returns nothing from listMetaTexts', $repo->listMetaTexts($postId, $LANG, $MISS) === []);

// ── 11. Board name search excludes attachments — no Media leak ──────────────
// listPostCollections()/postMetaCounts() don't filter by post_type on their own —
// CollectionBoard is what separates image attachments into the aggregated Media
// board, in PHP, AFTER these queries already decided which rows exist. Without an
// explicit post_type guard on the new name-match clauses (postNameClauses()), an
// attachment whose filename/title matches the search term would pull ALL its own
// rows into the result purely from that match, inflating Media's totals from a
// board this feature is explicitly not meant to touch.

test_section('board name search excludes attachments (no Media leak):');

$attachmentId = test_insert($wpdb->posts, [
    'post_author'       => 1,
    'post_date'         => $now,
    'post_date_gmt'     => $now,
    'post_content'      => '',
    'post_title'        => 'LINGOWP_TESTATTACH photo',
    'post_excerpt'      => '',
    'post_status'       => 'inherit',
    'comment_status'    => 'closed',
    'ping_status'       => 'closed',
    'post_name'         => 'lingowp-testattach-photo',
    'post_modified'     => $now,
    'post_modified_gmt' => $now,
    'post_type'         => 'attachment',
    'post_mime_type'    => 'image/jpeg',
]);
test_ok('fixture attachment created', $attachmentId > 0, "attachment_id=$attachmentId");

$repo->upsertSources([
    new PostMetaSource($attachmentId, '_wp_attachment_image_alt', null, 'A scenic mountain view'),
]);

test_ok('attachment title match does not surface it via listPostCollections',
    ! in_array($attachmentId, array_column($repo->listPostCollections($LANG, 'LINGOWP_TESTATTACH'), 'post_id'), true));
test_ok('attachment title match does not surface it via postMetaCounts',
    ! in_array($attachmentId, array_column($repo->postMetaCounts($LANG, 'LINGOWP_TESTATTACH'), 'post_id'), true));
test_ok("ordinary content match on the attachment's own alt text still works",
    count($repo->listMetaTexts($attachmentId, $LANG, 'scenic mountain')) === 1);

// ── 12. A filter still ANDs against a name-only match ────────────────────────
// The riskiest part of this change: searchPredicate()'s whole OR-group (content
// clauses + name clauses) must stay one contiguous parenthesized group before
// textFilterPredicate() appends its own " AND (...)" for filters. If that
// wrapping ever loosens, SQL's AND-binds-tighter-than-OR precedence would apply a
// filter to only the last disjunct instead of the whole predicate, silently
// over-broadening every filtered search on the board — not just name searches.

test_section('a filter still ANDs against a name-only match:');

test_ok('slug match, no filter: all 4 rows',
    count($repo->listPostTexts($postId, $LANG, 'lingowp-test-needle-page')) === 4);
test_ok('slug match + not_translated filter: intersects down to the 3 untranslated rows',
    count($repo->listPostTexts($postId, $LANG, 'lingowp-test-needle-page', ['origin' => ['not_translated']])) === 3);

test_done();

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

use TransLangs\Core\Repository\WpDbSourceTranslationRepository;
use TransLangs\Core\Source\Type\HtmlSource;
use TransLangs\Core\Source\Type\OptionSource;
use TransLangs\Core\Source\Type\PostMetaSource;
use TransLangs\Core\Source\Type\PostSource;
use TransLangs\Core\Source\Type\TermSource;
use TransLangs\Core\Source\Value\SourceRef;
use TransLangs\Core\Source\Value\TranslationStatus;
use TransLangs\Core\Text\TL_Key;
use TransLangs\Database\TranslationMemorySchema;

global $wpdb;

tl_guard();
tl_begin();

$repo    = new WpDbSourceTranslationRepository($wpdb);
$parents = TranslationMemorySchema::parentTables();
$PP      = $parents['tl_post'];
$LANG    = 'fr';
$OTHER   = 'es';
$MISS    = 'zzzznothingxyz';

$now = current_time('mysql');

// ── 1. Fixtures ──────────────────────────────────────────────────────────────

tl_section('fixtures:');

$makePost = static function (string $title, string $slug) use ($wpdb, $now): int {
    return tl_insert($wpdb->posts, [
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

$postId  = $makePost('TLTEST Needle Page', 'tltest-needle-page');
$postId2 = $makePost('TLTEST Wildcard Post', 'tltest-wildcard-post');
tl_ok('fixture posts created', $postId > 0 && $postId2 > 0, "post_id=$postId, post_id2=$postId2");

// A real term row: fk_term_owner requires an existing term_taxonomy_id.
$termId = tl_insert($wpdb->terms, ['name' => 'TLTEST Term', 'slug' => 'tltest-term', 'term_group' => 0]);
$ttId   = tl_insert($wpdb->term_taxonomy, [
    'term_id'     => $termId,
    'taxonomy'    => 'tltest_tax',
    'description' => '',
    'parent'      => 0,
    'count'       => 0,
]);
tl_ok('fixture term created', $ttId > 0, "term_taxonomy_id=$ttId");

$optionId = (int) tl_var("SELECT option_id FROM {$wpdb->options} WHERE option_name = 'blogname'");
tl_ok('blogname has a wp_options row', $optionId > 0, "option_id=$optionId");

$repo->upsertSources([
    new PostSource($postId, 'post_title', 'TLTEST Needle Page'),
    new PostSource($postId, 'post_content', 'The quick brown fox jumps'),
    new PostSource($postId, 'post_content', 'Read {1}the manual{/1} carefully'),
    new PostSource($postId, 'post_excerpt', 'A café in Paris'),
    new PostSource($postId2, 'post_title', 'Save 50% today'),
    new PostSource($postId2, 'post_excerpt', 'a_b marker'),
    new PostMetaSource($postId, '_yoast_wpseo_title', null, 'TLTEST meta needle'),
    new OptionSource($optionId, 'blogname', '', 'TLTEST Needle Site'),
    new HtmlSource('.tl-test-block', 'https://example.test/', 'TLTEST html needle'),
    new TermSource($ttId, 'name', 'TLTEST Needle Term'),
]);

$discovered = TranslationStatus::SOURCE_DISCOVERED;

$postSourceId = static fn(int $pid, string $field, string $text): int => (int) tl_var(
    "SELECT id FROM {$PP} WHERE post_id = %d AND field = %s AND original_hash = UNHEX(%s) AND status = %d",
    $pid,
    $field,
    strtolower(bin2hex(TL_Key::currentHash($text))),
    $discovered
);

$titleId = $postSourceId($postId, 'post_title', 'TLTEST Needle Page');
tl_ok('title source row found', $titleId > 0, "id=$titleId");

// Exactly one translated row; "brown fox" is deliberately left untranslated so §5
// can prove an untranslated original match survives the WHERE clause.
$repo->saveTranslation(
    SourceRef::make('post', $titleId),
    $LANG,
    'Page Aiguille TLTEST',
    TranslationStatus::MACHINE,
    'test'
);
$titleRow = $repo->listPostTexts($postId, $LANG, 'Needle Page')[0] ?? [];
tl_ok('fixture translation saved', ($titleRow['translated_text'] ?? null) === 'Page Aiguille TLTEST');

/** The collection row for our fixture post, or null — the dev site has other posts. */
$postColl = static function (string $search) use ($repo, $LANG, $postId): ?array {
    foreach ($repo->listPostCollections($LANG, $search) as $c) {
        if ($c['post_id'] === $postId) {
            return $c;
        }
    }
    return null;
};

// ── 2. Empty search is a no-op ───────────────────────────────────────────────
// The single most valuable guard here: it proves the unsearched board still runs
// exactly the query it ran before search existed.

tl_section('empty search is a no-op:');

tl_ok('listPostCollections', $repo->listPostCollections($LANG) == $repo->listPostCollections($LANG, ''));
tl_ok('postMetaCounts', $repo->postMetaCounts($LANG) == $repo->postMetaCounts($LANG, ''));
tl_ok('listTermCollections', $repo->listTermCollections($LANG) == $repo->listTermCollections($LANG, ''));
tl_ok('optionCollectionSummary', $repo->optionCollectionSummary($LANG) == $repo->optionCollectionSummary($LANG, ''));
tl_ok('htmlCollectionSummary', $repo->htmlCollectionSummary($LANG) == $repo->htmlCollectionSummary($LANG, ''));
tl_ok('listPostTexts', $repo->listPostTexts($postId, $LANG) == $repo->listPostTexts($postId, $LANG, ''));
tl_ok('listMetaTexts', $repo->listMetaTexts($postId, $LANG) == $repo->listMetaTexts($postId, $LANG, ''));
tl_ok('listTaxonomyTexts', $repo->listTaxonomyTexts('tltest_tax', $LANG) == $repo->listTaxonomyTexts('tltest_tax', $LANG, ''));
tl_ok('listOptionTexts', $repo->listOptionTexts($LANG) == $repo->listOptionTexts($LANG, ''));
tl_ok('listHtmlTexts', $repo->listHtmlTexts($LANG) == $repo->listHtmlTexts($LANG, ''));

// ── 3. Original-text match, per source type ──────────────────────────────────

tl_section('original text matches, all five board types:');

tl_ok('post', count($repo->listPostTexts($postId, $LANG, 'brown fox')) === 1);
tl_ok('meta', count($repo->listMetaTexts($postId, $LANG, 'meta needle')) === 1);
tl_ok('option', count($repo->listOptionTexts($LANG, 'TLTEST Needle Site')) === 1);
tl_ok('html', count($repo->listHtmlTexts($LANG, 'TLTEST html needle')) === 1);
tl_ok('term', count($repo->listTaxonomyTexts('tltest_tax', $LANG, 'TLTEST Needle Term')) === 1);

tl_ok('a miss returns nothing', $repo->listPostTexts($postId, $LANG, $MISS) === []);

// ── 4. Translation match, and language scoping ───────────────────────────────

tl_section('translated text matches, scoped to the language:');

tl_ok('translation matches in its own language',
    count($repo->listPostTexts($postId, $LANG, 'Aiguille')) === 1);
tl_ok('translation does NOT leak into another language',
    count($repo->listPostTexts($postId, $OTHER, 'Aiguille')) === 0);
tl_ok('original still matches in another language',
    count($repo->listPostTexts($postId, $OTHER, 'brown fox')) === 1);

// ── 5. The LEFT JOIN invariant — the core of the design ──────────────────────
// The predicate sits in WHERE. For an untranslated row `t.translated_text LIKE …`
// is NULL, and NULL OR TRUE is TRUE, so a row whose ORIGINAL matches survives with
// t.* still NULL and SUM(t.id IS NULL) keeps counting it as missing. If the
// predicate ever moves into the ON clause, these four assertions fail.

tl_section('LEFT JOIN invariant (counts describe the matching subset):');

$c = $postColl('brown fox');
tl_ok('untranslated original match survives the WHERE', $c !== null);
tl_ok('  total is the match count', $c !== null && $c['total'] === 1, 'total=' . ($c['total'] ?? 'n/a'));
tl_ok('  counted as missing', $c !== null && $c['missing'] === 1, 'missing=' . ($c['missing'] ?? 'n/a'));
tl_ok('  not counted as translated', $c !== null && $c['translated'] === 0);

$c2 = $postColl('Aiguille');
tl_ok('translation-only match returns the collection', $c2 !== null);
tl_ok('  total is the match count', $c2 !== null && $c2['total'] === 1, 'total=' . ($c2['total'] ?? 'n/a'));
tl_ok('  counted as translated', $c2 !== null && $c2['translated'] === 1);
tl_ok('  not counted as missing', $c2 !== null && $c2['missing'] === 0, 'missing=' . ($c2['missing'] ?? 'n/a'));

$c3 = $postColl('TLTEST');
tl_ok('a broader term widens the subset', $c3 !== null && $c3['total'] === 1 && $c3['translated'] === 1);

// ── 6. Groups with no hits disappear ─────────────────────────────────────────
// The GROUP BY emits no row for a collection with no matches, which is how the
// board hides empty groups with no filtering code of its own.

tl_section('empty groups vanish:');

tl_ok('no post collections', $repo->listPostCollections($LANG, $MISS) === []);
tl_ok('no meta counts', $repo->postMetaCounts($LANG, $MISS) === []);
tl_ok('no term collections', $repo->listTermCollections($LANG, $MISS) === []);
tl_ok('option summary total is 0', $repo->optionCollectionSummary($LANG, $MISS)['total'] === 0);
tl_ok('html summary total is 0', $repo->htmlCollectionSummary($LANG, $MISS)['total'] === 0);

// ── 7. esc_like — % and _ are literal, never wildcards ───────────────────────

tl_section('esc_like literalness:');

tl_ok('% matches literally', count($repo->listPostTexts($postId2, $LANG, '50%')) === 1);
tl_ok('% is not a wildcard', count($repo->listPostTexts($postId2, $LANG, 'Save%today')) === 0);
tl_ok('_ matches literally', count($repo->listPostTexts($postId2, $LANG, 'a_b')) === 1);
tl_ok('_ is not a wildcard', count($repo->listPostTexts($postId2, $LANG, 'axb')) === 0);
tl_ok('a lone backslash matches nothing', count($repo->listPostTexts($postId2, $LANG, '\\')) === 0);

// ── 8. Collation freebies (utf8mb4_unicode_520_ci) ───────────────────────────
// Case- and accent-insensitivity come from the collation, not from any code —
// these break silently if the table collation ever changes.

tl_section('case and accent folding:');

tl_ok('case-insensitive', count($repo->listPostTexts($postId, $LANG, 'BROWN FOX')) === 1);
tl_ok('accent-insensitive (cafe → café)', count($repo->listPostTexts($postId, $LANG, 'cafe')) === 1);
tl_ok('accented query matches too', count($repo->listPostTexts($postId, $LANG, 'café')) === 1);

// ── 9. Codec markers — pins the known limitation ─────────────────────────────
// Composed units store inline-tag markers. A phrase inside one segment matches; a
// phrase that CROSSES a marker boundary does not, because plain LIKE sees the
// markers. Fixing it needs REGEXP_REPLACE (MySQL 8+) or a shadow column (DDL).
// The second assertion documents the gap rather than hiding it — if it ever
// starts passing, the limitation is gone and this file should say so.

tl_section('codec markers:');

tl_ok('phrase inside one segment matches',
    count($repo->listPostTexts($postId, $LANG, 'the manual')) === 1);
tl_ok('text before a marker matches',
    count($repo->listPostTexts($postId, $LANG, 'Read ')) === 1);
tl_ok('KNOWN LIMIT: a phrase crossing a marker boundary does not match',
    count($repo->listPostTexts($postId, $LANG, 'Read the manual carefully')) === 0);

tl_done();

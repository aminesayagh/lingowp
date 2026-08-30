<?php

/**
 * Checklist §2 (core taxonomies) — automated coverage for what was verified by
 * hand this session via ZQTAX01-ZQTAX11 sentinels.
 * Run: wp eval-file tests/db/section2_taxonomies_it.php
 *
 * Term slug and author display-name ("never translated") are covered
 * structurally in tests/source_identity_check.php (TermSource/UserSource
 * reject those fields outright) — not duplicated here.
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Database\Repository\WpDbBoardQueries;
use LingoWP\Extraction\Structured\StructuredSourceScanner;
use LingoWP\Shared\Parsing\HtmlUnitExtractor;
use LingoWP\Shared\Parsing\TranslationUnitCodec;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Database\TranslationMemorySchema;

global $wpdb;

test_guard();
test_begin();

$repo    = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
$scanner = new StructuredSourceScanner($repo, new HtmlUnitExtractor(new TranslationUnitCodec()));
$parents = TranslationMemorySchema::parentTables();
$TP      = $parents['term'];
$TMP     = $parents['term_meta'];
$UP      = $parents['user'];
$discovered = TranslationStatus::SOURCE_DISCOVERED;

/** Raw wp_terms + wp_term_taxonomy fixture — mirrors board_search_it.php's pattern. */
$makeTerm = static function (string $taxonomy, string $name, string $description = '', int $parent = 0) use ($wpdb): array {
    $termId = test_insert($wpdb->terms, [
        'name'       => $name,
        'slug'       => sanitize_title($name) . '-' . wp_generate_password(6, false),
        'term_group' => 0,
    ]);
    $ttId = test_insert($wpdb->term_taxonomy, [
        'term_id'     => $termId,
        'taxonomy'    => $taxonomy,
        'description' => $description,
        'parent'      => $parent,
        'count'       => 0,
    ]);
    return [$termId, $ttId];
};

$fieldRows = static function (int $ttId, string $field) use ($wpdb, $TP, $discovered): int {
    return test_count("SELECT COUNT(*) FROM {$TP} WHERE term_taxonomy_id = %d AND field = %s AND status = %d", $ttId, $field, $discovered);
};

// ── 1. Category / tag / custom taxonomy name + description ──────────────────

test_section('term name + description across taxonomies:');

[, $catTt] = $makeTerm('category', 'LINGOWP_TEST Category', 'LINGOWP_TEST category description');
$scanner->refreshTerm($catTt, 'category', 'LINGOWP_TEST Category', 'LINGOWP_TEST category description');
test_ok('category name discovered', $fieldRows($catTt, 'name') === 1, "tt=$catTt");
test_ok('category description discovered', $fieldRows($catTt, 'description') === 1, "tt=$catTt");

[, $tagTt] = $makeTerm('post_tag', 'LINGOWP_TEST Tag', 'LINGOWP_TEST tag description');
$scanner->refreshTerm($tagTt, 'post_tag', 'LINGOWP_TEST Tag', 'LINGOWP_TEST tag description');
test_ok('tag name discovered', $fieldRows($tagTt, 'name') === 1, "tt=$tagTt");
test_ok('tag description discovered', $fieldRows($tagTt, 'description') === 1, "tt=$tagTt");

register_taxonomy('lingowp_test_tax', 'post', ['public' => true, 'label' => 'LINGOWP_TEST Custom Tax']);
[, $customTt] = $makeTerm('lingowp_test_tax', 'LINGOWP_TEST Custom Term', 'LINGOWP_TEST custom description');
$scanner->refreshTerm($customTt, 'lingowp_test_tax', 'LINGOWP_TEST Custom Term', 'LINGOWP_TEST custom description');
test_ok('custom taxonomy name discovered', $fieldRows($customTt, 'name') === 1, "tt=$customTt");
test_ok('custom taxonomy description discovered', $fieldRows($customTt, 'description') === 1, "tt=$customTt");

// ── 2. Nested term: parent + child discovered independently ─────────────────

test_section('nested (parent/child) term:');

[, $parentTt] = $makeTerm('category', 'LINGOWP_TEST Parent Cat');
[, $childTt]  = $makeTerm('category', 'LINGOWP_TEST Child Cat', '', $parentTt);
$scanner->refreshTerm($parentTt, 'category', 'LINGOWP_TEST Parent Cat', '');
$scanner->refreshTerm($childTt, 'category', 'LINGOWP_TEST Child Cat', '');
test_ok('parent term discovered', $fieldRows($parentTt, 'name') === 1, "tt=$parentTt");
test_ok('child term discovered independently', $fieldRows($childTt, 'name') === 1, "tt=$childTt");

// ── 3. Accent / non-ASCII name preserved byte-for-byte ───────────────────────

test_section('accent / non-ASCII term name:');

$accentName = 'LINGOWP_TEST Café élève 中文';
[, $accentTt] = $makeTerm('category', $accentName);
$scanner->refreshTerm($accentTt, 'category', $accentName, '');
$storedAccent = (string) test_var("SELECT original_text FROM {$TP} WHERE term_taxonomy_id = %d AND field = 'name'", $accentTt);
test_ok('accent/non-ASCII name preserved exactly', $storedAccent === $accentName, "got '$storedAccent'");

// ── 4. Term meta: empty allow-list by default, opt-in per key via the filter ─

test_section('term meta allow-list (checklist §2, the ACF-field gap):');

test_ok('TranslatableTermMetaKeys is empty by default',
    \LingoWP\Shared\Source\TranslatableTermMetaKeys::keys() === []);

[$notAllowedTermId, $notAllowedTt] = $makeTerm('category', 'LINGOWP_TEST TermMeta NotAllowed');
update_term_meta($notAllowedTermId, 'lingowp_test_not_allowed', 'LINGOWP_TEST should never be scanned');
$scanner->refreshTermMeta($notAllowedTermId);
test_ok('a key never added to the filter is never scanned',
    test_count("SELECT COUNT(*) FROM {$TMP} WHERE term_id = %d", $notAllowedTermId) === 0);

add_filter('lingowp_translatable_term_meta_keys', static fn(array $k): array => [...$k, 'lingowp_test_scalar', 'lingowp_test_repeater']);

[$scalarTermId, ] = $makeTerm('category', 'LINGOWP_TEST TermMeta Scalar');
update_term_meta($scalarTermId, 'lingowp_test_scalar', 'LINGOWP_TEST plain text value');
$scanner->refreshTermMeta($scalarTermId);
test_ok('an allow-listed scalar key is discovered as one leaf',
    test_count("SELECT COUNT(*) FROM {$TMP} WHERE term_id = %d AND meta_key = 'lingowp_test_scalar'", $scalarTermId) === 1);

[$repeaterTermId, ] = $makeTerm('category', 'LINGOWP_TEST TermMeta Repeater');
update_term_meta($repeaterTermId, 'lingowp_test_repeater', [
    ['title' => 'LINGOWP_TEST row one'],
    ['title' => 'LINGOWP_TEST row two'],
]);
$scanner->refreshTermMeta($repeaterTermId);
$leafContexts = $wpdb->get_col($wpdb->prepare(
    "SELECT context FROM {$TMP} WHERE term_id = %d AND meta_key = 'lingowp_test_repeater' ORDER BY context",
    $repeaterTermId
));
test_ok('a repeater-shaped value produces one leaf per row with correct context paths',
    $leafContexts === ['0.title', '1.title'], 'got ' . json_encode($leafContexts));

// ── 5. Author bio: discovered when set, pruned when cleared ──────────────────

test_section('author bio:');

$bioUserLogin = 'lingowp_test_bio_' . wp_generate_password(6, false);
$bioUserId = test_insert($wpdb->users, [
    'user_login'    => $bioUserLogin,
    'user_pass'     => wp_hash_password('x'),
    'user_nicename' => $bioUserLogin,
    'user_email'    => $bioUserLogin . '@example.test',
    'user_registered' => current_time('mysql'),
    'display_name'  => 'LINGOWP_TEST Bio Author',
]);
update_user_meta($bioUserId, 'description', 'LINGOWP_TEST bio text');
$scanner->refreshUser($bioUserId);
test_ok('a bio is discovered',
    test_count("SELECT COUNT(*) FROM {$UP} WHERE user_id = %d AND status = %d", $bioUserId, $discovered) === 1);

update_user_meta($bioUserId, 'description', '');
$scanner->refreshUser($bioUserId);
test_ok('clearing the bio prunes the row, not leaves it stale',
    test_count("SELECT COUNT(*) FROM {$UP} WHERE user_id = %d", $bioUserId) === 0);

test_done();

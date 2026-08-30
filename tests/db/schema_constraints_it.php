<?php

/**
 * Schema constraint check — READ ONLY, no transaction, writes nothing.
 * Run: wp eval-file tests/db/schema_constraints_it.php
 * Mirrors §"Phase 2 — schema_constraints_it" of plan-database-pipeline-verification.md.
 *
 * Runs first: if the database shape is wrong, every assertion in the other
 * integration scripts is meaningless.
 *
 * Note this file ENUMERATES the foreign keys from information_schema and diffs
 * them against an explicit expected set, rather than calling
 * ConstraintVerifier::findMissing() and stopping. findMissing() only asks the
 * code its own question, so it cannot catch the code being wrong about what
 * should exist.
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\ConstraintVerifier;
use LingoWP\Database\TranslationMemorySchema;

global $wpdb;

test_guard();
// No test_begin(): this file only reads.

$parents = TranslationMemorySchema::parentTables();
$schema  = DB_NAME;

// The two WooCommerce-conditional tables are deliberately NOT in
// TranslationMemorySchema::parentTables() (see its own doc + WpDbSourceRepository::parent()) —
// merged into this test's LOCAL copy only, so every loop below that already
// iterates $parents/$expectedFks/$expectedUniq picks them up for free when
// WooCommerce is active, and skips them cleanly (they simply don't exist) when
// it isn't — keeps this suite portable to a non-WooCommerce lingowp install.
$wooTablesActive = class_exists('WooCommerce');
if ($wooTablesActive) {
    $parents['woo_attribute_label'] = TranslationMemorySchema::wooAttributeLabelTable();
    $parents['woo_tax_rate']        = TranslationMemorySchema::wooTaxRateTable();
}

// ── A. Foreign keys, enumerated ──────────────────────────────────────────────

test_section('foreign keys (enumerated from information_schema):');

$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT CONSTRAINT_NAME AS name, TABLE_NAME AS tbl, REFERENCED_TABLE_NAME AS ref
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = %s
       AND REFERENCED_TABLE_NAME IS NOT NULL
       AND TABLE_NAME LIKE %s",
    $schema,
    $wpdb->prefix . 'lingowp\_%'
), ARRAY_A);

$found = [];
foreach ((array) $rows as $r) {
    $found[(string) $r['name']] = (string) $r['tbl'];
}

$expectedFks = [
    // parent -> WordPress
    'fk_post_owner'      => $parents['post'],
    'fk_meta_owner'      => $parents['meta'],
    'fk_term_owner'      => $parents['term'],
    'fk_option_owner'    => $parents['option'],
    'fk_user_owner'      => $parents['user'],
    'fk_term_meta_owner' => $parents['term_meta'],
    // child -> parent
    'fk_meta_tr_parent'      => $parents['meta'] . '_translation',
    'fk_term_meta_tr_parent' => $parents['term_meta'] . '_translation',
];

if ($wooTablesActive) {
    // parent -> WooCommerce (not WordPress core) — the one deliberate
    // exception to this schema's usual "owner is a WP-core table" rule.
    $expectedFks['fk_woo_attribute_label_owner'] = $parents['woo_attribute_label'];
    $expectedFks['fk_woo_tax_rate_owner']        = $parents['woo_tax_rate'];
}

foreach ($expectedFks as $name => $table) {
    test_ok("  $name exists on $table",
        isset($found[$name]) && $found[$name] === $table,
        isset($found[$name]) ? "found on {$found[$name]}" : 'NOT FOUND');
}

$unexpected = array_diff_key($found, $expectedFks);
test_ok('no unexpected FKs on lingowp_* tables', $unexpected === [], 'extra: ' . json_encode(array_keys($unexpected)));

// Cross-check the code's own verifier agrees with reality.
$verifier = new ConstraintVerifier($wpdb);
$missing  = $verifier->findMissing(TranslationMemorySchema::expectedForeignKeys());
test_ok('ConstraintVerifier reports nothing missing', $missing === [], 'missing: ' . json_encode($missing));

// The two WooCommerce FKs are deliberately NOT in expectedForeignKeys() — that
// method covers only the unconditional core install; the woo pair has its own
// separate install path (see TranslationMemorySchema::maybeInstallWooCommerceTables())
// and is checked directly above instead. Excluded from this specific count so
// adding them to $expectedFks above doesn't make this comparison drift.
$coreExpectedFkCount = count($expectedFks) - ($wooTablesActive ? 2 : 0);
test_ok('expectedForeignKeys() covers every enumerated core FK above',
    count(TranslationMemorySchema::expectedForeignKeys()) === $coreExpectedFkCount,
    'expected ' . $coreExpectedFkCount . ', got ' . count(TranslationMemorySchema::expectedForeignKeys()));

// ── B. Hash-link children have NO foreign key, by design ─────────────────────

test_section('hash-link children carry no FK (by design):');

foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $key) {
    $child = $parents[$key] . '_translation';
    $n = test_count(
        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL",
        $schema,
        $child
    );
    test_ok("  $child has zero FKs", $n === 0, "found $n");
}

// ── C. Unique keys ───────────────────────────────────────────────────────────

test_section('unique keys:');

/** @return string[] ordered column list of an index */
function test_index_cols(string $schema, string $table, string $index): array
{
    global $wpdb;
    $cols = $wpdb->get_col($wpdb->prepare(
        "SELECT COLUMN_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s
         ORDER BY SEQ_IN_INDEX",
        $schema,
        $table,
        $index
    ));
    return array_map('strval', (array) $cols);
}

$expectedUniq = [
    'post'      => ['post_id', 'field', 'original_hash'],
    'meta'      => ['post_id', 'meta_key_hash', 'context_hash', 'original_hash'],
    'term'      => ['term_taxonomy_id', 'field', 'original_hash'],
    'html'      => ['original_hash'],
    'option'    => ['option_id', 'option_path_hash', 'original_hash'],
    'user'      => ['user_id', 'field', 'original_hash'],
    'term_meta' => ['term_id', 'meta_key_hash', 'context_hash', 'original_hash'],
];

if ($wooTablesActive) {
    $expectedUniq['woo_attribute_label'] = ['attribute_id', 'original_hash'];
    $expectedUniq['woo_tax_rate']        = ['tax_rate_id', 'original_hash'];
}

foreach ($expectedUniq as $key => $cols) {
    $got = test_index_cols($schema, $parents[$key], 'uniq_source');
    test_ok("  {$key}.uniq_source", $got === $cols,
        'expected ' . json_encode($cols) . ', got ' . json_encode($got));
}

// Hash-link SHAPED (original_hash, lang_code) child tables: the usual
// HASH_LINK_CHILDREN list plus the two WooCommerce ones, which are hash-link
// shaped too but deliberately excluded from that shared list (see
// WpDbSourceRepository::isHashLink()'s doc comment) so generic maintenance
// jobs that loop over it don't touch a table that might not exist.
$hashLinkShapedChildren = TranslationMemorySchema::HASH_LINK_CHILDREN;
if ($wooTablesActive) {
    $hashLinkShapedChildren[] = 'woo_attribute_label';
    $hashLinkShapedChildren[] = 'woo_tax_rate';
}

foreach ($hashLinkShapedChildren as $key) {
    $child = $parents[$key] . '_translation';
    $got   = test_index_cols($schema, $child, 'uniq_translation');
    test_ok("  {$key}_translation.uniq_translation", $got === ['original_hash', 'lang_code'],
        'got ' . json_encode($got));
}

foreach (array_unique(TranslationMemorySchema::PARENT_ID_CHILD_FKS) as $key) {
    $tr = test_index_cols($schema, $parents[$key] . '_translation', 'uniq_translation');
    test_ok("  {$key}_translation keyed (parent_id, lang_code)", $tr === ['parent_id', 'lang_code'],
        'got ' . json_encode($tr));
}

// idx_hash is what makes the orphan sweep's NOT EXISTS join cheap, needed
// wherever original_hash isn't the leftmost column of uniq_source. html is
// keyed on original_hash alone (see HtmlSource's class doc), so uniq_source
// itself already covers the sweep's lookup there — a separate idx_hash would
// just duplicate it.
foreach ($expectedUniq as $key => $_) {
    if ($key === 'html') {
        continue;
    }
    $got = test_index_cols($schema, $parents[$key], 'idx_hash');
    test_ok("  {$key}.idx_hash exists (sweep depends on it)", $got === ['original_hash'],
        'got ' . json_encode($got));
}

// ── D. Storage engines ───────────────────────────────────────────────────────

test_section('storage engines (InnoDB required for FKs and for test rollback):');

$engineOf = static function (string $table) use ($schema): string {
    return (string) test_var(
        "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
        $schema,
        $table
    );
};

foreach ([$wpdb->posts, $wpdb->term_taxonomy, $wpdb->options] as $wpTable) {
    test_ok("  $wpTable is InnoDB", strtoupper($engineOf($wpTable)) === 'INNODB', 'got ' . $engineOf($wpTable));
}

foreach ($parents as $key => $table) {
    test_ok("  $key is InnoDB", strtoupper($engineOf($table)) === 'INNODB', 'got ' . $engineOf($table));
    $child = $table . '_translation';
    test_ok("  {$key}_translation is InnoDB", strtoupper($engineOf($child)) === 'INNODB', 'got ' . $engineOf($child));
}

test_done();

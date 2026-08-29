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

// ── A. Foreign keys, enumerated ──────────────────────────────────────────────

test_section('foreign keys (enumerated from information_schema):');

$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT CONSTRAINT_NAME AS name, TABLE_NAME AS tbl, REFERENCED_TABLE_NAME AS ref
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = %s
       AND REFERENCED_TABLE_NAME IS NOT NULL
       AND TABLE_NAME LIKE %s",
    $schema,
    $wpdb->prefix . 'tl\_%'
), ARRAY_A);

$found = [];
foreach ((array) $rows as $r) {
    $found[(string) $r['name']] = (string) $r['tbl'];
}

$expectedFks = [
    // parent -> WordPress
    'fk_post_owner'     => $parents['post'],
    'fk_meta_owner'     => $parents['meta'],
    'fk_term_owner'     => $parents['term'],
    'fk_option_owner'   => $parents['option'],
    // child -> parent
    'fk_meta_tr_parent' => $parents['meta'] . '_translation',
];

foreach ($expectedFks as $name => $table) {
    test_ok("  $name exists on $table",
        isset($found[$name]) && $found[$name] === $table,
        isset($found[$name]) ? "found on {$found[$name]}" : 'NOT FOUND');
}

$unexpected = array_diff_key($found, $expectedFks);
test_ok('no unexpected FKs on tl_* tables', $unexpected === [], 'extra: ' . json_encode(array_keys($unexpected)));

// Cross-check the code's own verifier agrees with reality.
$verifier = new ConstraintVerifier($wpdb);
$missing  = $verifier->findMissing(TranslationMemorySchema::expectedForeignKeys());
test_ok('ConstraintVerifier reports nothing missing', $missing === [], 'missing: ' . json_encode($missing));

test_ok('expectedForeignKeys() covers all five',
    count(TranslationMemorySchema::expectedForeignKeys()) === 5,
    'got ' . count(TranslationMemorySchema::expectedForeignKeys()));

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
    'post'   => ['post_id', 'field', 'original_hash'],
    'meta'   => ['post_id', 'meta_key_hash', 'context_hash', 'original_hash'],
    'term'   => ['term_taxonomy_id', 'field', 'original_hash'],
    'html'   => ['original_hash'],
    'option' => ['option_id', 'option_path_hash', 'original_hash'],
];

foreach ($expectedUniq as $key => $cols) {
    $got = test_index_cols($schema, $parents[$key], 'uniq_source');
    test_ok("  {$key}.uniq_source", $got === $cols,
        'expected ' . json_encode($cols) . ', got ' . json_encode($got));
}

foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $key) {
    $child = $parents[$key] . '_translation';
    $got   = test_index_cols($schema, $child, 'uniq_translation');
    test_ok("  {$key}_translation.uniq_translation", $got === ['original_hash', 'lang_code'],
        'got ' . json_encode($got));
}

$metaTr = test_index_cols($schema, $parents['meta'] . '_translation', 'uniq_translation');
test_ok('  meta_translation keyed (parent_id, lang_code)', $metaTr === ['parent_id', 'lang_code'],
    'got ' . json_encode($metaTr));

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

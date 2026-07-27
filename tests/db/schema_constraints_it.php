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

use TransLangs\Database\ConstraintVerifier;
use TransLangs\Database\TranslationMemorySchema;
use TransLangs\Core\Repository\WpDbSourceTranslationRepository;

global $wpdb;

tl_guard();
// No tl_begin(): this file only reads.

$parents = TranslationMemorySchema::parentTables();
$schema  = DB_NAME;

// ── A. Foreign keys, enumerated ──────────────────────────────────────────────

tl_section('foreign keys (enumerated from information_schema):');

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
    'fk_post_owner'       => $parents['tl_post'],
    'fk_meta_owner'       => $parents['tl_meta'],
    'fk_term_owner'       => $parents['tl_term'],
    'fk_option_owner'     => $parents['tl_option'],
    // parent -> parent
    'fk_plural_owner'     => TranslationMemorySchema::pluralTable(),
    // child -> parent
    'fk_meta_tr_parent'   => $parents['tl_meta'] . '_translation',
    'fk_static_tr_parent' => $parents['tl_staticText'] . '_translation',
];

foreach ($expectedFks as $name => $table) {
    tl_ok("  $name exists on $table",
        isset($found[$name]) && $found[$name] === $table,
        isset($found[$name]) ? "found on {$found[$name]}" : 'NOT FOUND');
}

$unexpected = array_diff_key($found, $expectedFks);
tl_ok('no unexpected FKs on tl_* tables', $unexpected === [], 'extra: ' . json_encode(array_keys($unexpected)));

// Cross-check the code's own verifier agrees with reality.
$verifier = new ConstraintVerifier($wpdb);
$missing  = $verifier->findMissing(TranslationMemorySchema::expectedForeignKeys());
tl_ok('ConstraintVerifier reports nothing missing', $missing === [], 'missing: ' . json_encode($missing));

tl_ok('expectedForeignKeys() covers all seven',
    count(TranslationMemorySchema::expectedForeignKeys()) === 7,
    'got ' . count(TranslationMemorySchema::expectedForeignKeys()));

// ── B. Hash-link children have NO foreign key, by design ─────────────────────

tl_section('hash-link children carry no FK (by design):');

foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $key) {
    $child = $parents[$key] . '_translation';
    $n = tl_count(
        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL",
        $schema,
        $child
    );
    tl_ok("  $child has zero FKs", $n === 0, "found $n");
}

// ── C. Unique keys ───────────────────────────────────────────────────────────

tl_section('unique keys:');

/** @return string[] ordered column list of an index */
function tl_index_cols(string $schema, string $table, string $index): array
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
    'tl_post'       => ['post_id', 'field', 'original_hash'],
    'tl_meta'       => ['post_id', 'meta_key_hash', 'context_hash', 'original_hash'],
    'tl_term'       => ['term_taxonomy_id', 'field', 'original_hash'],
    'tl_staticText' => ['source', 'reference_hash', 'original_hash'],
    'tl_html'       => ['selector_hash', 'original_hash'],
    'tl_option'     => ['option_id', 'option_path_hash', 'original_hash'],
];

foreach ($expectedUniq as $key => $cols) {
    $got = tl_index_cols($schema, $parents[$key], 'uniq_source');
    tl_ok("  {$key}.uniq_source", $got === $cols,
        'expected ' . json_encode($cols) . ', got ' . json_encode($got));
}

foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $key) {
    $child = $parents[$key] . '_translation';
    $got   = tl_index_cols($schema, $child, 'uniq_translation');
    tl_ok("  {$key}_translation.uniq_translation", $got === ['original_hash', 'lang_code'],
        'got ' . json_encode($got));
}

$metaTr = tl_index_cols($schema, $parents['tl_meta'] . '_translation', 'uniq_translation');
tl_ok('  tl_meta_translation keyed (parent_id, lang_code)', $metaTr === ['parent_id', 'lang_code'],
    'got ' . json_encode($metaTr));

$statTr = tl_index_cols($schema, $parents['tl_staticText'] . '_translation', 'uniq_translation');
tl_ok('  tl_staticText_translation includes form_index',
    $statTr === ['parent_id', 'lang_code', 'form_index'], 'got ' . json_encode($statTr));

$plural = tl_index_cols($schema, TranslationMemorySchema::pluralTable(), 'uniq_plural');
tl_ok('  tl_staticPluralText is 1:1 with its anchor', $plural === ['static_text_id'],
    'got ' . json_encode($plural));

// idx_hash is what makes the orphan sweep's NOT EXISTS join cheap.
foreach ($expectedUniq as $key => $_) {
    $got = tl_index_cols($schema, $parents[$key], 'idx_hash');
    tl_ok("  {$key}.idx_hash exists (sweep depends on it)", $got === ['original_hash'],
        'got ' . json_encode($got));
}

// ── D. Storage engines ───────────────────────────────────────────────────────

tl_section('storage engines (InnoDB required for FKs and for test rollback):');

$engineOf = static function (string $table) use ($schema): string {
    return (string) tl_var(
        "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
        $schema,
        $table
    );
};

foreach ([$wpdb->posts, $wpdb->term_taxonomy, $wpdb->options] as $wpTable) {
    tl_ok("  $wpTable is InnoDB", strtoupper($engineOf($wpTable)) === 'INNODB', 'got ' . $engineOf($wpTable));
}

foreach ($parents as $key => $table) {
    tl_ok("  $key is InnoDB", strtoupper($engineOf($table)) === 'INNODB', 'got ' . $engineOf($table));
    $child = $table . '_translation';
    tl_ok("  {$key}_translation is InnoDB", strtoupper($engineOf($child)) === 'INNODB', 'got ' . $engineOf($child));
}

// ── E. The duplicated hash-link list must never drift ────────────────────────

tl_section('duplicated constants:');

$repoHashLink = (new ReflectionClass(WpDbSourceTranslationRepository::class))->getConstant('HASH_LINK');
tl_ok('HASH_LINK_CHILDREN === WpDbSourceTranslationRepository::HASH_LINK',
    TranslationMemorySchema::HASH_LINK_CHILDREN === $repoHashLink,
    'schema=' . json_encode(TranslationMemorySchema::HASH_LINK_CHILDREN) . ' repo=' . json_encode($repoHashLink));

tl_ok('pluralTable() is NOT in parentTables()',
    !in_array(TranslationMemorySchema::pluralTable(), $parents, true));

tl_done();

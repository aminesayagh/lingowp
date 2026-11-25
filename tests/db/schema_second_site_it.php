<?php

/**
 * Multisite: a second site's tables install in the SAME database as the
 * first. MySQL FK names are unique per database, so the old fixed names
 * (fk_post_owner…) made every site after the first fail activation —
 * the WordPress.org review finding. This runs the real install steps
 * (createContentTables() + addForeignKeys()) a second time under another
 * prefix, next to this site's own, and checks each table got its FK. Also:
 * dbDelta() wants no change on this site's existing tables.
 * Run: wp eval-file tests/db/schema_second_site_it.php
 *
 * Creates `lvtest2_*` tables (pointing at this site's WordPress tables, which
 * is all an FK name clash needs) and drops them all at the end.
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\TableDefinitions;
use LingoWP\Database\TranslationMemorySchema;

global $wpdb;

test_guard();

$cc = $wpdb->get_charset_collate();
$p  = array_map(static fn (string $table): string => 'lvtest2_' . substr($table, strlen($wpdb->prefix)), TranslationMemorySchema::parentTables());

test_section('second site tables, same database (the real install steps, another prefix):');

TranslationMemorySchema::createContentTables($p, $cc);
$created = [];
foreach (TranslationMemorySchema::foreignKeyDefinitions($p) as [$table]) {
    $created[$table] = true;
}
foreach (array_keys($created) as $table) {
    test_ok("  $table created", $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table);
}
test_ok('every second-site table carries its FK (addForeignKeys() reports all in place)', TranslationMemorySchema::addForeignKeys($p));

test_section('dbDelta() is idempotent on existing tables:');
// Asked BEFORE anything runs dbDelta() for real on this site's tables, which
// the old raw CREATE TABLE made: dbDelta(…, false) only reports what it would
// ALTER. Any entry means a definition drifted from the live table.
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$site = TranslationMemorySchema::parentTables();
$sql  = [
    TableDefinitions::post($site['post'], $cc),
    TableDefinitions::meta($site['meta'], $cc),
    TableDefinitions::term($site['term'], $cc),
    TableDefinitions::html($site['html'], $cc),
    TableDefinitions::option($site['option'], $cc),
    TableDefinitions::user($site['user'], $cc),
    TableDefinitions::termMeta($site['term_meta'], $cc),
    TableDefinitions::gettextUnit(TranslationMemorySchema::gettextUnitTable(), $cc),
];
foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $key) {
    $sql[] = TableDefinitions::hashLinkTranslation($site[$key] . '_translation', $cc);
}
foreach (TranslationMemorySchema::PARENT_ID_CHILD_FKS as $key) {
    $sql[] = TableDefinitions::parentIdTranslation($site[$key] . '_translation', $cc);
}
$changes = dbDelta($sql, false);
test_ok("this site's 15 existing tables: dbDelta() finds nothing to change", $changes === [], json_encode($changes));

// Children first: the translation tables reference their parents.
foreach ([...array_map(static fn (string $t): string => $t . '_translation', array_values($p)), ...array_values($p)] as $table) {
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table)); // phpcs:ignore WordPress.DB -- integration test, fixed lvtest2_* names
}
test_ok('test tables dropped', (int) $wpdb->get_var(
    $wpdb->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', 'lvtest2\_%')
) === 0);

test_done();

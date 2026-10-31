<?php

/**
 * Multisite: a second site's tables install in the SAME database as the
 * first. MySQL FK names are unique per database, so the old fixed names
 * (fk_post_owner…) made every site after the first fail activation —
 * the WordPress.org review finding. This creates every FK-carrying table a
 * second time under another prefix, next to this site's own, and checks each
 * one got its FK.
 * Run: wp eval-file tests/db/schema_second_site_it.php
 *
 * Creates `lvtest2_*` tables (pointing at this site's WordPress tables, which
 * is all an FK name clash needs) and drops them all at the end.
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\ConstraintVerifier;
use LingoWP\Database\TableDefinitions;

global $wpdb;

test_guard();

$cc = $wpdb->get_charset_collate();
$t  = static fn (string $key): string => 'lvtest2_lingowp_' . $key;

// Same parent → referenced pairs as TranslationMemorySchema::install().
$create = [
    $t('post')      => TableDefinitions::post($t('post'), $wpdb->posts, $cc),
    $t('meta')      => TableDefinitions::meta($t('meta'), $wpdb->posts, $cc),
    $t('term')      => TableDefinitions::term($t('term'), $wpdb->term_taxonomy, $cc),
    $t('option')    => TableDefinitions::option($t('option'), $wpdb->options, $cc),
    $t('user')      => TableDefinitions::user($t('user'), $wpdb->users, $cc),
    $t('term_meta') => TableDefinitions::termMeta($t('term_meta'), $wpdb->terms, $cc),
    $t('meta_translation')      => TableDefinitions::parentIdTranslation($t('meta_translation'), $t('meta'), $cc),
    $t('term_meta_translation') => TableDefinitions::parentIdTranslation($t('term_meta_translation'), $t('term_meta'), $cc),
];
$expected = [
    $t('post')      => $wpdb->posts,
    $t('meta')      => $wpdb->posts,
    $t('term')      => $wpdb->term_taxonomy,
    $t('option')    => $wpdb->options,
    $t('user')      => $wpdb->users,
    $t('term_meta') => $wpdb->terms,
    $t('meta_translation')      => $t('meta'),
    $t('term_meta_translation') => $t('term_meta'),
];

test_section('second site tables, same database:');

$wpdb->suppress_errors(true);
foreach ($create as $table => $sql) {
    $wpdb->query($sql); // phpcs:ignore WordPress.DB
    test_ok("  $table created", $wpdb->last_error === '', $wpdb->last_error);
}
$wpdb->suppress_errors(false);

$missing = (new ConstraintVerifier($wpdb))->findMissing($expected);
test_ok('every second-site table carries its FK', $missing === [], 'missing: ' . json_encode($missing));

// Children first: the translation tables reference their parents.
foreach (array_reverse(array_keys($create)) as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $table); // phpcs:ignore WordPress.DB
}
test_ok('test tables dropped', (int) $wpdb->get_var(
    $wpdb->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', 'lvtest2\_%')
) === 0);

test_done();

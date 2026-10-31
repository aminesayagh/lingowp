<?php

/**
 * `.mo` files live in uploads (WordPress.org review: plugins may not write
 * under wp-content/languages), and the files 1.6.3 wrote to the old
 * WP_LANG_DIR/lingowp folder are moved there once, never lost.
 * Run: wp eval-file tests/db/gettext_legacy_dir_it.php
 *
 * Touches the filesystem only, with a domain no real plugin uses, and removes
 * everything it created.
 */

require __DIR__ . '/_harness.php';

use LingoWP\GettextDomains\GettextPoImporter;

test_guard();

$base   = GettextPoImporter::baseDir();
$legacy = GettextPoImporter::legacyDir();
$domain = 'linvara-legacy-dir-test';

test_ok('baseDir is inside uploads', strpos($base, wp_upload_dir(null, false)['basedir'] . '/') === 0, $base);
test_ok('baseDir is outside wp-content/languages', strpos($base, WP_LANG_DIR) !== 0, $base);
test_ok('the plugin boot already emptied the old folder', ! is_dir($legacy), $legacy);

// A file only in the old folder, and one in both (the new copy must win).
wp_mkdir_p($legacy . '/plugins');
wp_mkdir_p($legacy . '/themes');
wp_mkdir_p($base . '/plugins');
file_put_contents($legacy . '/plugins/' . $domain . '-fr_FR.mo', 'old-only');
file_put_contents($legacy . '/themes/' . $domain . '-de_DE.mo', 'old-theme');
file_put_contents($legacy . '/plugins/' . $domain . '-es_ES.mo', 'old-stale');
file_put_contents($base . '/plugins/' . $domain . '-es_ES.mo', 'new-wins');

GettextPoImporter::migrateLegacyDir();

$moved = $base . '/plugins/' . $domain . '-fr_FR.mo';
$theme = $base . '/themes/' . $domain . '-de_DE.mo';
$kept  = $base . '/plugins/' . $domain . '-es_ES.mo';

test_ok('plugin file moved to the new folder', is_readable($moved) && file_get_contents($moved) === 'old-only');
test_ok('theme file moved to the new folder', is_readable($theme) && file_get_contents($theme) === 'old-theme');
test_ok('a file already in the new folder is not overwritten', file_get_contents($kept) === 'new-wins');
test_ok('old folder removed once everything moved', ! is_dir($legacy));

// The loader now resolves a domain's file to the new folder.
$loaded = (new \LingoWP\GettextDomains\GettextMoFileLoader())->moFile(WP_LANG_DIR . '/plugins/' . $domain . '-fr_FR.mo', $domain);
test_ok('loader points WordPress at the moved file', $loaded === $moved, $loaded);

wp_delete_file($moved);
wp_delete_file($theme);
wp_delete_file($kept);

test_done();

<?php

/**
 * `.mo` files live in uploads (WordPress.org review: plugins may not write
 * under wp-content/languages), WordPress's loader finds them there, and
 * nothing is ever written to the pre-1.6.4 wp-content/languages/lingowp folder.
 * Run: wp eval-file tests/db/gettext_mo_dir_it.php
 *
 * Touches the filesystem only, with a domain no real plugin uses, and removes
 * everything it created.
 */

require __DIR__ . '/_harness.php';

use LingoWP\GettextDomains\GettextMoFileLoader;
use LingoWP\GettextDomains\GettextPoImporter;

test_guard();

$base   = GettextPoImporter::baseDir();
$domain = 'linvara-mo-dir-test';

test_ok('baseDir is inside uploads', strpos($base, wp_upload_dir(null, false)['basedir'] . '/') === 0, $base);
test_ok('baseDir is outside wp-content/languages', strpos($base, WP_LANG_DIR) !== 0, $base);

// A file Linvara wrote is what WordPress loads for that domain.
$file = $base . '/plugins/' . $domain . '-fr_FR.mo';
wp_mkdir_p(dirname($file));
file_put_contents($file, 'mo');

$loaded = (new GettextMoFileLoader())->moFile(WP_LANG_DIR . '/plugins/' . $domain . '-fr_FR.mo', $domain);
test_ok('loader points WordPress at the uploads file', $loaded === $file, $loaded);

wp_delete_file($file);

// A plugin boot (this run's) never creates the old folder again.
test_ok('nothing written to wp-content/languages/lingowp', ! is_dir(WP_LANG_DIR . '/lingowp'), WP_LANG_DIR . '/lingowp');

test_done();

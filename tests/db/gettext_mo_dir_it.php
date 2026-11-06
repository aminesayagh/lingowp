<?php

/**
 * `.mo` files live in uploads (WordPress.org review: plugins may not write
 * under wp-content/languages), WordPress's loader finds them there, and
 * nothing is ever written to the pre-1.6.4 wp-content/languages/lingowp folder.
 * Run: wp eval-file tests/db/gettext_mo_dir_it.php
 *
 * Touches the filesystem with a domain no real plugin uses and removes every
 * file it created; its one DB write (saveUnit's counter) is rolled back.
 */

require __DIR__ . '/_harness.php';

use LingoWP\GettextDomains\GettextMoFileLoader;
use LingoWP\GettextDomains\GettextPoImporter;
use LingoWP\GettextDomains\GettextTemplateGenerator;
use LingoWP\GettextDomains\GettextTranslationUnitsService;
use LingoWP\GettextDomains\GettextUnitRepository;

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

// targetPath() is the one place a .mo path is built (WordPress.org review: an
// unvalidated domain/locale let a request write outside uploads/linvara).
$importer = new GettextPoImporter();
foreach ([
    ['plugin', 'woocommerce', 'fr_FR'],
    ['theme', 'twentytwentyfive', 'de_DE_formal'],
    ['plugin', 'wpforms-lite', 'pt_PT_ao90'],
    ['plugin', 'some_plugin.v2', 'ar'],
    ['plugin', '_private-domain', 'fr_FR'],
] as [$t, $d, $l]) {
    $p = $importer->targetPath($t, $d, $l);
    test_ok("accepted: $t $d $l", $p !== null && strpos($p, $base . '/') === 0, (string) $p);
}
foreach ([
    ['plugin', '../../../../evil', 'fr_FR'],
    ['plugin', 'a/b', 'fr_FR'],
    ['plugin', 'a\\b', 'fr_FR'],
    ['plugin', '.hidden', 'fr_FR'],
    ['plugin', '-flag', 'fr_FR'],
    ['plugin', 'x..y', 'fr_FR'],
    ['plugin', '', 'fr_FR'],
    ['plugin', 'woocommerce', '../../evil'],
    ['plugin', 'woocommerce', 'fr_FR/../x'],
    ['plugin', 'woocommerce', 'fr-FR'],
    ['plugin', 'woocommerce', ''],
    ['bogus', 'woocommerce', 'fr_FR'],
] as [$t, $d, $l]) {
    test_ok("refused: '$t' '$d' '$l'", $importer->targetPath($t, $d, $l) === null);
}

// End to end through saveUnit(), which writes a .mo directly (import() only
// accepts real HTTP uploads, so it cannot be driven from the command line).
// Its board counter is a DB write, rolled back by test_begin().
test_begin();
$units    = new GettextTranslationUnitsService(new GettextTemplateGenerator(), $importer, new GettextUnitRepository($GLOBALS['wpdb']));
// Where `plugins/../../../evil` would land: languages/plugins → up three = uploads/.
$escapeTo = dirname($base, 2) . '/evil-fr_FR.mo';
test_ok('traversal save is refused', $units->saveUnit('plugin', '../../../evil', 'fr_FR', 'Hello', null, 'Bonjour') === false);
test_ok('...and writes no file outside uploads/linvara', ! file_exists($escapeTo), $escapeTo);
test_ok('a traversal locale is refused too', $units->saveUnit('plugin', $domain, '../../x', 'Hello', null, 'Bonjour') === false);
test_ok('a normal save still writes inside uploads/linvara', $units->saveUnit('plugin', $domain, 'fr_FR', 'Hello', null, 'Bonjour') === true
    && is_readable($importer->targetPath('plugin', $domain, 'fr_FR')));
wp_delete_file($importer->targetPath('plugin', $domain, 'fr_FR'));

// A plugin boot (this run's) never creates the old folder again.
test_ok('nothing written to wp-content/languages/lingowp', ! is_dir(WP_LANG_DIR . '/lingowp'), WP_LANG_DIR . '/lingowp');

test_done();

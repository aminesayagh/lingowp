<?php

/**
 * Gettext source scanner — finds strings whatever nikic/php-parser copy is
 * loaded (another plugin, e.g. Plugin Check, can load its own v4 before ours).
 * Run: wp eval-file tests/db/gettext_scanner_it.php
 * Also: an empty scan result is only remembered as "scanned" when the
 * scanner proves it works, and the pre-fix marker option is no longer read.
 */

require __DIR__ . '/_harness.php';

use Gettext\Translations;
use LingoWP\GettextDomains\GettextPoImporter;
use LingoWP\GettextDomains\GettextTemplateGenerator;
use LingoWP\GettextDomains\GettextTranslationUnitsService;
use LingoWP\GettextDomains\GettextUnitRepository;
use LingoWP\GettextDomains\Scanner\WordPressPhpScanner;

global $wpdb;

test_guard();
test_begin();

$parserFile = (new ReflectionClass(\PhpParser\NodeTraverser::class))->getFileName();
$isV4       = (new ReflectionMethod(\PhpParser\NodeTraverser::class, '__construct'))->getNumberOfParameters() === 0;
echo '  php-parser loaded from: ' . $parserFile . ($isV4 ? ' (v4 constructor)' : ' (v5 constructor)') . "\n";

test_section('scanner (snippet):');
$translations = Translations::create('d');
$scanner      = new WordPressPhpScanner($translations);
$scanner->setDefaultDomain('d');
$scanner->ignoreInvalidFunctions(true);
$scanner->scanString("<?php __('Hello', 'd'); _e('Bye'); _x('Post', 'noun', 'd'); __('Other', 'x');", 'snippet.php');

$found = [];
foreach ($translations as $t) {
    $found[] = ($t->getContext() !== null ? $t->getContext() . '|' : '') . $t->getOriginal();
}
sort($found);
test_ok('own-domain + default-domain strings found, other domain skipped',
    $found === ['Bye', 'Hello', 'noun|Post'], json_encode($found));

test_section('scanner (real plugin source):');
$woo = WP_PLUGIN_DIR . '/woocommerce';
if (is_dir($woo)) {
    $entries = (new GettextTemplateGenerator())->scan($woo, 'woocommerce');
    test_ok('WooCommerce source yields > 1000 strings', is_array($entries) && count($entries) > 1000,
        'got ' . (is_array($entries) ? count($entries) : 'null'));
} else {
    echo "  (skipped: woocommerce not installed)\n";
}

test_section('scanner with another plugin\'s php-parser v4 forced to load first:');
// The in-process checks above depend on which plugin happened to load its
// php-parser first. This runs a fresh PHP process that loads Plugin Check's
// v4 with priority over LingoWP's own — the exact conflict that emptied the board.
$v4Autoload = WP_PLUGIN_DIR . '/plugin-check/vendor/autoload.php';
if (is_file($v4Autoload)) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/scanner_v4_probe.php')
        . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/vendor/autoload.php') . ' ' . escapeshellarg($v4Autoload) . ' 2>&1';
    $out   = (string) shell_exec($cmd);
    $probe = json_decode(trim($out), true);
    test_ok('probe ran under php-parser v4', is_array($probe) && $probe['v4'] === true, $out);
    test_ok('v4: own-domain + default-domain strings found', is_array($probe) && $probe['found'] === 3, $out);
} else {
    echo "  (skipped: plugin-check not installed, no v4 php-parser to force)\n";
}

test_section('scan markers:');
// Every write here — including the option writes from markScanned() — is
// rolled back by test_begin()'s transaction (wp_options is InnoDB), even on a
// fatal, so there is no manual option cleanup.
$type  = 'plugin';
$empty = '_test-empty';
$dir   = sys_get_temp_dir() . '/lingowp-scan-' . wp_generate_password(8, false);
mkdir($dir);
file_put_contents($dir . '/main.php', "<?php // a component with no translatable strings\n");

$repo = new GettextUnitRepository($wpdb);

test_ok('the pre-fix marker option is no longer the one read',
    GettextUnitRepository::OPTION_SCANNED !== 'lingowp_gettext_scanned_domains');
test_ok('scanner self-check passes with the fix in place', (new GettextTemplateGenerator())->isWorking());

// A broken scanner: finds nothing anywhere, and its self-check says so.
$broken = new class extends GettextTemplateGenerator {
    public function scan(string $path, string $domain): ?array { return []; }
    public function isWorking(): bool { return false; }
};
$service = new GettextTranslationUnitsService($broken, new GettextPoImporter(), $repo);
test_ok('broken scanner: list() still answers (empty list, not an error)', $service->list($dir, $type, $empty, 'fr_FR') === []);
test_ok('broken scanner: the empty result is NOT remembered as scanned', $repo->wasScanned($type, $empty) === false);

// A working scanner on a component that genuinely has no strings: remembered,
// so Translate All's scan budget isn't spent on it again on every request.
$service = new GettextTranslationUnitsService(new GettextTemplateGenerator(), new GettextPoImporter(), $repo);
test_ok('working scanner: genuinely empty component lists as empty', $service->list($dir, $type, $empty, 'fr_FR') === []);
test_ok('working scanner: genuinely empty component IS remembered as scanned', $repo->wasScanned($type, $empty) === true);

unlink($dir . '/main.php');
rmdir($dir);

test_done();

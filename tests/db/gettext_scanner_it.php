<?php

/**
 * Gettext source scanner on real plugin source, and the scan markers: an
 * empty scan result is only remembered as "scanned" when the scanner proves
 * it works, and the pre-fix marker option is no longer read.
 * Run: wp eval-file tests/db/gettext_scanner_it.php
 * Edge cases of the scanner itself: php tests/gettext_scanner_check.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\GettextDomains\GettextPoImporter;
use LingoWP\GettextDomains\GettextTemplateGenerator;
use LingoWP\GettextDomains\GettextTranslationUnitsService;
use LingoWP\GettextDomains\GettextUnitRepository;

global $wpdb;

test_guard();
test_begin();

test_section('scanner (real plugin source):');
$woo = WP_PLUGIN_DIR . '/woocommerce';
if (is_dir($woo)) {
    $entries = (new GettextTemplateGenerator())->scan($woo, 'woocommerce');
    test_ok('WooCommerce source yields > 1000 strings', is_array($entries) && count($entries) > 1000,
        'got ' . (is_array($entries) ? count($entries) : 'null'));
} else {
    echo "  (skipped: woocommerce not installed)\n";
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

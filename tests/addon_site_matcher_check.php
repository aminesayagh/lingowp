<?php

/**
 * Standalone check for AddonSiteMatcher::matches() — the "does this add-on
 * matter on this site" comparison behind the Billing > Integrations tab.
 * Run:  php tests/addon_site_matcher_check.php
 * Exits non-zero if any case fails. No WordPress, no DB.
 */

declare(strict_types=1);

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

use LingoWP\Backend\AddonSiteMatcher;

$failed = 0;
$total  = 0;

function ok(string $label, bool $cond): void
{
    global $failed, $total;
    $total++;
    if ($cond) {
        echo "PASS  $label\n";
        return;
    }
    $failed++;
    echo "FAIL  $label\n";
}

$plugins = ['elementor/elementor.php', 'woocommerce/woocommerce.php', 'lingowp/lingowp.php'];
$theme   = 'bricks';

ok('empty detects is always relevant (native tool)', AddonSiteMatcher::matches([], [], ''));
ok('plugin basename hit', AddonSiteMatcher::matches(['elementor/elementor.php'], $plugins, $theme));
ok('any one of several detects is enough', AddonSiteMatcher::matches(['advanced-custom-fields-pro/acf.php', 'woocommerce/woocommerce.php'], $plugins, $theme));
ok('plugin basename miss', !AddonSiteMatcher::matches(['advanced-custom-fields-pro/acf.php'], $plugins, $theme));
ok('theme slug hit', AddonSiteMatcher::matches(['theme:bricks'], $plugins, $theme));
ok('theme slug miss', !AddonSiteMatcher::matches(['theme:Divi'], $plugins, $theme));
ok('theme slug is case-sensitive', !AddonSiteMatcher::matches(['theme:Bricks'], $plugins, $theme));
ok('theme entry never matches a plugin basename', !AddonSiteMatcher::matches(['theme:elementor/elementor.php'], $plugins, $theme));
ok('bare plugin entry never matches the theme slug', !AddonSiteMatcher::matches(['bricks'], $plugins, $theme));
ok('exact basename only, no prefix match', !AddonSiteMatcher::matches(['elementor'], $plugins, $theme));

echo "\n{$total} checks, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

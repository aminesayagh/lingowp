<?php

/**
 * The switcher's design options: saved site-wide settings (one option, REST
 * endpoint), per-switcher shortcode overrides, and administrator-only custom
 * CSS. Every value is validated — shortcode attributes come from any post
 * author, so this is where CSS injection would otherwise get in.
 *
 * Covers:
 *   1  validation: colors and lengths kept/normalized, injections dropped
 *   2  saved settings printed once in <head> as CSS variables, nothing when unset
 *   3  shortcode / PHP overrides land in that switcher's style attribute only
 *   4  custom CSS: printed only when enabled, tags stripped, admin-only
 *   5  REST: logged-out refused, invalid value -> 400, manager without
 *      unfiltered_html cannot touch custom CSS, administrator can
 *   6  dropdown arrow: shown by default, hidden per switcher or site-wide,
 *      never on list layouts, saved through REST
 *   7  padding once boxed (border or non-transparent background), size
 *      sm|md|lg (md default), saved or per switcher, REST validation
 *   8  dropdown shadow: none|sm|md|lg (md default), dropdowns only, legacy
 *      shadow="false", saved or per switcher, REST validation
 *   9  button width: fit (default) | full, only for a dropdown with a box
 *      (border or background), saved or per switcher, REST validation
 *  10  square flags: flag_radius above 8px (px, em at 16px, % of ~20px) uses
 *      the 1x1 files, square size; saved or per switcher; eo falls back
 *  11  open animation: off by default, on per switcher or site-wide,
 *      dropdowns only, saved through REST
 *  12  admin preview: renders the real template with an UNSAVED draft,
 *      head CSS from the draft, invalid fields reported, custom CSS gated
 *  13  floating switcher: footer output only when enabled, corner, distance,
 *      always a boxed fit-width dropdown, REST save/validation, preview HTML
 *  14  uninstall removes the option
 *
 * Run: ./wp eval-file tests/db/switcher_design_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\PluginDataCleanup;
use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Language\Infrastructure\SupportedLanguages;
use LingoWP\LocalizationRouting\LanguageLinks;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;
use LingoWP\Switcher\Switcher;
use LingoWP\Switcher\SwitcherSettings;
use LingoWP\Switcher\SwitcherShortcode;

test_guard();
test_begin();

delete_option(SwitcherSettings::OPTION); // start from "nothing saved" (rolled back)

/** What printHeadStyles() writes into <head>. */
function design_head(): string
{
    ob_start();
    SwitcherSettings::printHeadStyles();

    return (string) ob_get_clean();
}

/** A REST call as the current user; returns [status, data]. */
function design_rest(string $method, array $body = []): array
{
    $request = new WP_REST_Request($method, '/lingowp/v1/switcher-settings');
    if ($body !== []) {
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    $response = rest_do_request($request);

    return [$response->get_status(), $response->get_data()];
}

// ── 1. Validation ───────────────────────────────────────────────────────────

test_section('1. Validation');

$cases = [
    // [key, input, expected]
    ['bg_color', '#FFF', '#fff'],
    ['bg_color', '#11223380', '#11223380'],
    ['bg_color', 'rgba(0, 0, 0, .5)', 'rgba(0, 0, 0, .5)'],
    ['bg_color', 'hsl(120 50% 50% / .5)', 'hsl(120 50% 50% / .5)'],
    ['text_color', 'Red', 'red'],
    ['text_color', 'transparent', 'transparent'],
    ['border_radius', '8', '8px'],
    ['border_radius', '50%', '50%'],
    ['flag_radius', '0.5em', '0.5em'],
    ['dropdown_radius', '12', '12px'],
    ['dropdown_radius', '4px;}', ''],
    ['button_gap', '12', '12px'],
    ['item_gap', '0.75rem', '0.75rem'],
    ['item_gap', '50%', ''],
    ['button_gap', '150px', ''],
    ['border_width', '1.5', '1.5px'],
    ['border_width', '2rem', '2rem'],
    // rejected
    ['bg_color', 'red;}body{display:none', ''],
    ['bg_color', 'url(https://example.com/x.png)', ''],
    ['bg_color', '"red"', ''],
    ['bg_color', 'expression(alert(1))', ''],
    ['bg_color', '#ff0000;', ''],
    ['bg_color', 'rgb(0,0,0);x', ''],
    ['border_width', '25px', ''],
    ['border_width', '10%', ''],
    ['border_radius', '150%', ''],
    ['border_radius', '8px;color:red', ''],
];
foreach ($cases as [$key, $input, $expect]) {
    $got = SwitcherSettings::sanitizeValue($key, $input);
    test_ok(sprintf('%s %s -> %s', $key, var_export($input, true), $expect === '' ? 'rejected' : $expect), $got === $expect, 'got ' . var_export($got, true));
}

// ── 2. Saved settings in <head> ─────────────────────────────────────────────

test_section('2. Saved settings in <head>');

test_ok('nothing saved -> nothing printed', design_head() === '', design_head());

SwitcherSettings::update(['bg_color' => '#123456', 'border_radius' => '8', 'text_color' => 'nope;}'], false);
$head = design_head();
test_ok(
    'saved values printed once as variables on .lingowp-switcher',
    str_contains($head, '<style id="lingowp-switcher-settings">.lingowp-switcher{--lingowp-switcher-bg:#123456;--lingowp-switcher-radius:8px}</style>'),
    $head
);
test_ok('an invalid saved value is dropped', !str_contains($head, 'nope') && !str_contains($head, '--lingowp-switcher-text:'), $head);

SwitcherSettings::update(['bg_color' => ''], false);
test_ok('"" clears a saved value', !str_contains(design_head(), '--lingowp-switcher-bg:'), design_head());

// ── 3. Per-switcher overrides ───────────────────────────────────────────────

test_section('3. Shortcode / PHP overrides');

$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();
$store->save(['code' => 'zz_ZZ', 'slug' => 'zz']);
$registry->enableLanguage('zz_ZZ');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/lingowp-test-design/';
set_query_var('lingowp_lang', '');
$resolver = new ResolveRequestLanguage($registry);
$switcher = new Switcher(new LanguageLinks($resolver, new LocalizedUrlBuilder($resolver), new SupportedLanguages($store)));

/** The nav's style attribute, or null when it has none. */
function design_nav_style(string $html): ?string
{
    return preg_match('#<nav\b[^>]*?\sstyle="([^"]*)"#s', $html, $m) ? html_entity_decode($m[1]) : null;
}

$style = design_nav_style($switcher->render(['bg_color' => '#000', 'flag_radius' => '50%']));
test_ok('PHP args land in this switcher\'s style', $style === '--lingowp-switcher-bg:#000;--lingowp-switcher-flag-radius:50%', var_export($style, true));

$style = design_nav_style((new SwitcherShortcode($switcher))->shortcode(['text_hover_color' => '#f00', 'border_width' => '1']));
test_ok('shortcode attributes land in this switcher\'s style', $style === '--lingowp-switcher-text-hover:#f00;--lingowp-switcher-border-width:1px', var_export($style, true));

$style = design_nav_style((new SwitcherShortcode($switcher))->shortcode(['bg_color' => 'red;}body{display:none', 'border_radius' => '4']));
test_ok('an injected shortcode value is dropped, the valid one kept', $style === '--lingowp-switcher-radius:4px', var_export($style, true));

$style = design_nav_style((new SwitcherShortcode($switcher))->shortcode(['dropdown_radius' => '10']));
test_ok('dropdown_radius (shortcode) lands in this switcher\'s style', $style === '--lingowp-switcher-dropdown-radius:10px', var_export($style, true));

$style = design_nav_style((new SwitcherShortcode($switcher))->shortcode(['button_gap' => '8', 'item_gap' => '1em']));
test_ok('button_gap / item_gap (shortcode) land in this switcher\'s style', $style === '--lingowp-switcher-button-gap:8px;--lingowp-switcher-item-gap:1em', var_export($style, true));

test_ok('no overrides -> no style attribute at all', design_nav_style($switcher->render([])) === null);

// ── 4. Custom CSS ───────────────────────────────────────────────────────────

test_section('4. Custom CSS');

SwitcherSettings::update(['custom_css' => '.lingowp-switcher{letter-spacing:1px}', 'custom_css_enabled' => false], true);
test_ok('saved but disabled -> not printed', !str_contains(design_head(), 'lingowp-switcher-custom-css'), design_head());

SwitcherSettings::update(['custom_css' => '.lingowp-switcher > a{letter-spacing:1px}</style><script>alert(1)</script>', 'custom_css_enabled' => true], true);
$head = design_head();
test_ok('enabled -> printed in its own <style>', str_contains($head, '<style id="lingowp-switcher-custom-css">.lingowp-switcher > a{letter-spacing:1px}'), $head);
test_ok('tags are stripped: it cannot close <style> or open <script>', !str_contains($head, '<script') && substr_count($head, '</style>') === 1 + (int) str_contains($head, 'lingowp-switcher-settings'), $head);

$before = SwitcherSettings::get()['custom_css'];
SwitcherSettings::update(['custom_css' => 'body{display:none}'], false);
test_ok('update() without the CSS capability leaves the custom CSS alone', SwitcherSettings::get()['custom_css'] === $before);

// ── 5. REST ─────────────────────────────────────────────────────────────────

test_section('5. REST endpoint');

$previousUser = get_current_user_id();
$admins       = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$adminId      = (int) ($admins[0] ?? 0);
test_ok('an administrator exists (setup)', $adminId > 0);

wp_set_current_user(0);
[$status] = design_rest('GET');
test_ok('logged out: refused', in_array($status, [401, 403], true), "status $status");

wp_set_current_user($adminId);
[$status, $data] = design_rest('POST', ['bg_color' => '#abcdef', 'border_radius' => '6']);
test_ok('administrator saves design values', $status === 200 && ($data['bg_color'] ?? '') === '#abcdef' && ($data['border_radius'] ?? '') === '6px', "status $status " . wp_json_encode($data));

[$status, $data] = design_rest('POST', ['bg_color' => 'red;}body{display:none']);
test_ok('an invalid value is ERR_INVALID_INPUT naming the field', ($data['ok'] ?? null) === false && ($data['error'] ?? '') === 'ERR_INVALID_INPUT' && in_array('bg_color', (array) ($data['fields'] ?? []), true), "status $status " . wp_json_encode($data));
test_ok('... and nothing was saved', SwitcherSettings::get()['design']['bg_color'] === '#abcdef');

[$status, $data] = design_rest('POST', ['custom_css' => '.lingowp-switcher{gap:2px}', 'custom_css_enabled' => true]);
test_ok('administrator saves custom CSS', $status === 200 && ($data['custom_css'] ?? '') === '.lingowp-switcher{gap:2px}' && ($data['custom_css_enabled'] ?? false) === true, "status $status");

// A site manager who may NOT publish unfiltered HTML (multisite admins, or
// DISALLOW_UNFILTERED_HTML): same user, capability removed.
$noUnfiltered = static fn(array $caps, string $cap): array => $cap === 'unfiltered_html' ? ['do_not_allow'] : $caps;
add_filter('map_meta_cap', $noUnfiltered, 10, 2);

[$status, $data] = design_rest('POST', ['custom_css' => 'body{display:none}']);
test_ok('manager without unfiltered_html: custom CSS refused (ERR_FORBIDDEN)', ($data['ok'] ?? null) === false && ($data['error'] ?? '') === 'ERR_FORBIDDEN', "status $status " . wp_json_encode($data));
test_ok('... and the saved CSS is unchanged', SwitcherSettings::get()['custom_css'] === '.lingowp-switcher{gap:2px}');

[$status, $data] = design_rest('POST', ['text_color' => '#222']);
test_ok('... but can still save design values', $status === 200 && ($data['text_color'] ?? '') === '#222' && ($data['can_edit_css'] ?? true) === false, "status $status " . wp_json_encode($data));

remove_filter('map_meta_cap', $noUnfiltered, 10);
wp_set_current_user($previousUser);

// ── 6. Dropdown arrow ───────────────────────────────────────────────────────

test_section('6. Dropdown arrow (show_arrow)');

delete_option(SwitcherSettings::OPTION); // back to defaults (rolled back)

$hasArrow = static fn(string $html): bool => (bool) preg_match('#<nav\b[^>]*class="[^"]*\blingowp-switcher--arrow\b#s', $html);

test_ok('default: a dropdown shows its arrow', $hasArrow($switcher->render([])));
test_ok('list layouts never get one (no button to put it on)', !$hasArrow($switcher->render(['layout' => 'horizontal'])) && !$hasArrow($switcher->render(['layout' => 'vertical'])));
test_ok('show_arrow => false (PHP) hides it', !$hasArrow($switcher->render(['show_arrow' => false])));
test_ok('show_arrow="false" (shortcode) hides it', !$hasArrow((new SwitcherShortcode($switcher))->shortcode(['show_arrow' => 'false'])));

SwitcherSettings::update(['show_arrow' => false], false);
test_ok('saved show_arrow=false hides it site-wide', !$hasArrow($switcher->render([])));
test_ok('... and show_arrow="true" brings it back for one switcher', $hasArrow((new SwitcherShortcode($switcher))->shortcode(['show_arrow' => 'true'])));

wp_set_current_user($adminId);
[$status, $data] = design_rest('POST', ['show_arrow' => true]);
test_ok('REST saves show_arrow', $status === 200 && ($data['show_arrow'] ?? null) === true && SwitcherSettings::get()['show_arrow'] === true, "status $status " . wp_json_encode($data));
wp_set_current_user($previousUser);

// ── 7. Boxed padding and size ──────────────────────────────────────────────

test_section('7. Padding once boxed (border, background or hover background), size sm|md|lg');

delete_option(SwitcherSettings::OPTION); // back to defaults (rolled back)

$classes = static function (string $html): string {
    return preg_match('#<nav\b[^>]*class="([^"]*)"#s', $html, $m) ? $m[1] : '';
};
$has = static fn(string $html, string $class): bool => (bool) preg_match('/(^|\s)' . preg_quote($class, '/') . '(\s|$)/', $classes($html));

test_ok('no border, no background: not boxed (no padding)', !$has($switcher->render([]), 'lingowp-switcher--boxed'));
test_ok('default size is md', $has($switcher->render([]), 'lingowp-switcher--md'));
test_ok('a border makes it boxed', $has($switcher->render(['border_width' => '1']), 'lingowp-switcher--boxed'));
test_ok('a background alone makes it boxed', $has($switcher->render(['bg_color' => '#000']), 'lingowp-switcher--boxed'));
test_ok('a hover background alone makes it boxed', $has($switcher->render(['bg_hover_color' => '#eee']), 'lingowp-switcher--boxed'));
test_ok('... but not a transparent one', !$has($switcher->render(['bg_hover_color' => 'transparent']), 'lingowp-switcher--boxed'));
test_ok('border 0 / transparent background: not boxed', !$has($switcher->render(['border_width' => '0', 'bg_color' => 'transparent']), 'lingowp-switcher--boxed'));
test_ok('list layouts get it too (padded items)', $has($switcher->render(['layout' => 'vertical', 'border_width' => '1']), 'lingowp-switcher--boxed'));

$html = (new SwitcherShortcode($switcher))->shortcode(['border_width' => '1', 'size' => 'sm']);
test_ok('size="sm" (shortcode) -> lingowp-switcher--sm', $has($html, 'lingowp-switcher--sm') && !$has($html, 'lingowp-switcher--md'), $classes($html));
test_ok('an unknown size falls back to md', $has($switcher->render(['size' => 'xl']), 'lingowp-switcher--md'));

SwitcherSettings::update(['border_width' => '1', 'size' => 'lg'], false);
test_ok('a saved border boxes every switcher', $has($switcher->render([]), 'lingowp-switcher--boxed'));
test_ok('a saved size applies everywhere', $has($switcher->render([]), 'lingowp-switcher--lg'));
test_ok('... and a switcher can drop the box with border_width="0"', !$has($switcher->render(['border_width' => '0']), 'lingowp-switcher--boxed'));

wp_set_current_user($adminId);
[$status, $data] = design_rest('POST', ['size' => 'sm']);
test_ok('REST saves size', $status === 200 && ($data['size'] ?? '') === 'sm', "status $status " . wp_json_encode($data));
[$status, $data] = design_rest('POST', ['size' => 'huge']);
test_ok('REST: an unknown size is ERR_INVALID_INPUT naming the field', ($data['error'] ?? '') === 'ERR_INVALID_INPUT' && in_array('size', (array) ($data['fields'] ?? []), true), "status $status");
wp_set_current_user($previousUser);

// ── 8. Dropdown shadow ──────────────────────────────────────────────────────

test_section('8. Dropdown shadow (none | sm | md | lg)');

delete_option(SwitcherSettings::OPTION); // back to defaults (rolled back)

$shadowClass = static function (string $html): string {
    return preg_match('#<nav\b[^>]*class="[^"]*\blingowp-switcher--shadow-([a-z]+)\b#s', $html, $m) ? $m[1] : 'none';
};

test_ok('default: md', $shadowClass($switcher->render([])) === 'md', $shadowClass($switcher->render([])));
test_ok('dropdown_shadow="none" -> no shadow class', $shadowClass((new SwitcherShortcode($switcher))->shortcode(['dropdown_shadow' => 'none'])) === 'none');
test_ok('dropdown_shadow="lg" -> lg', $shadowClass((new SwitcherShortcode($switcher))->shortcode(['dropdown_shadow' => 'lg'])) === 'lg');
test_ok('an unknown value falls back to md', $shadowClass($switcher->render(['dropdown_shadow' => 'xl'])) === 'md');
test_ok('list layouts never get one', $shadowClass($switcher->render(['layout' => 'vertical'])) === 'none' && $shadowClass($switcher->render(['layout' => 'horizontal', 'dropdown_shadow' => 'lg'])) === 'none');
test_ok('previous switcher\'s shadow="false" -> none', $shadowClass((new SwitcherShortcode($switcher))->shortcode(['shadow' => 'false'])) === 'none');
test_ok('previous switcher\'s shadow="true" -> the default', $shadowClass((new SwitcherShortcode($switcher))->shortcode(['shadow' => 'true'])) === 'md');

SwitcherSettings::update(['dropdown_shadow' => 'sm'], false);
test_ok('a saved shadow applies everywhere', $shadowClass($switcher->render([])) === 'sm');

wp_set_current_user($adminId);
[$status, $data] = design_rest('POST', ['dropdown_shadow' => 'lg']);
test_ok('REST saves dropdown_shadow', $status === 200 && ($data['dropdown_shadow'] ?? '') === 'lg', "status $status " . wp_json_encode($data));
[$status, $data] = design_rest('POST', ['dropdown_shadow' => 'huge']);
test_ok('REST: an unknown shadow is ERR_INVALID_INPUT naming the field', ($data['error'] ?? '') === 'ERR_INVALID_INPUT' && in_array('dropdown_shadow', (array) ($data['fields'] ?? []), true), "status $status");
wp_set_current_user($previousUser);

// ── 9. Button width ─────────────────────────────────────────────────────────

test_section('9. Button width (fit | full)');

delete_option(SwitcherSettings::OPTION); // back to defaults (rolled back)

$isFull = static fn(string $html): bool => (bool) preg_match('#<nav\b[^>]*class="[^"]*\blingowp-switcher--full\b#s', $html);
$sc     = new SwitcherShortcode($switcher);

test_ok('default: fit', !$isFull($switcher->render(['bg_color' => '#000'])));
test_ok('full + background -> full width', $isFull($sc->shortcode(['bg_color' => '#000', 'button_width' => 'full'])));
test_ok('full + border -> full width', $isFull($sc->shortcode(['border_width' => '1', 'button_width' => 'full'])));
test_ok('full without border or background: ignored (no visible box)', !$isFull($sc->shortcode(['button_width' => 'full'])));
test_ok('list layouts never get it (no button)', !$isFull($sc->shortcode(['layout' => 'vertical', 'bg_color' => '#000', 'button_width' => 'full'])));
test_ok('an unknown value falls back to fit', !$isFull($sc->shortcode(['bg_color' => '#000', 'button_width' => 'wide'])));

SwitcherSettings::update(['button_width' => 'full', 'border_width' => '1'], false);
test_ok('saved full + saved border: every dropdown is full width', $isFull($switcher->render([])));
test_ok('... and a switcher can go back to fit', !$isFull($sc->shortcode(['button_width' => 'fit'])));

wp_set_current_user($adminId);
[$status, $data] = design_rest('POST', ['button_width' => 'fit']);
test_ok('REST saves button_width', $status === 200 && ($data['button_width'] ?? '') === 'fit', "status $status " . wp_json_encode($data));
[$status, $data] = design_rest('POST', ['button_width' => 'stretch']);
test_ok('REST: an unknown width is ERR_INVALID_INPUT naming the field', ($data['error'] ?? '') === 'ERR_INVALID_INPUT' && in_array('button_width', (array) ($data['fields'] ?? []), true), "status $status");
wp_set_current_user($previousUser);

// ── 10. Square flags ────────────────────────────────────────────────────────

test_section('10. Square flags once flag_radius is above 8px');

delete_option(SwitcherSettings::OPTION); // back to defaults (rolled back)

// A real locale, so there is a flag to look at (zz_ZZ has none).
$store->save(['code' => 'fr_FR', 'slug' => 'lwt-fr']);
$registry->enableLanguage('fr_FR');
$_SERVER['REQUEST_URI'] = '/lingowp-test-design/';
set_query_var('lingowp_lang', '');
$flagResolver = new ResolveRequestLanguage($registry);
$flagSwitcher = new Switcher(new LanguageLinks($flagResolver, new LocalizedUrlBuilder($flagResolver), new SupportedLanguages($store)));

/** The fr flag <img> of a render: [src, height, square class?]. */
$frFlag = static function (string $html): array {
    if (!preg_match('#<img class="([^"]*)" src="([^"]*/fr\.svg)"[^>]*height="(\d+)"#', $html, $m)) {
        return ['(no fr flag)', '', false];
    }
    return [$m[2], $m[3], str_contains($m[1], 'lingowp-switcher__flag--square')];
};
$render = static fn(array $args): array => $frFlag($flagSwitcher->render(['layout' => 'vertical', 'include' => ['en_US', 'fr_FR']] + $args));

[$src, $h, $sq] = $render([]);
test_ok('default (2px): the 4x3 flag', !str_contains($src, '/1x1/') && $h === '15' && !$sq, "$src h=$h");
[$src, $h, $sq] = $render(['flag_radius' => '8']);
test_ok('8px exactly: still 4x3 ("more than 8px")', !str_contains($src, '/1x1/') && !$sq, $src);
[$src, $h, $sq] = $render(['flag_radius' => '10']);
test_ok('10px: the 1x1 flag, square size', str_contains($src, '/flags/1x1/fr.svg') && $h === '20' && $sq, "$src h=$h");
[$src] = $render(['flag_radius' => '50%']);
test_ok('50% (~10px): square', str_contains($src, '/1x1/'), $src);
[$src] = $render(['flag_radius' => '30%']);
test_ok('30% (~6px): rectangular', !str_contains($src, '/1x1/'), $src);
[$src] = $render(['flag_radius' => '0.75em']);
test_ok('0.75em (12px): square', str_contains($src, '/1x1/'), $src);

SwitcherSettings::update(['flag_radius' => '12'], false);
[$src] = $render([]);
test_ok('a saved 12px radius squares every switcher\'s flags', str_contains($src, '/1x1/'), $src);
[$src] = $render(['flag_radius' => '2px']);
test_ok('... and a switcher can go back to 4x3 with 2px', !str_contains($src, '/1x1/'), $src);

test_ok('FlagResolver: the 1x1 file for fr_FR', str_ends_with((string) LingoWP\Language\Domain\FlagResolver::url('fr_FR', '1x1'), '/flags/1x1/fr.svg'));
test_ok('FlagResolver: no square eo file -> the 4x3 one', str_ends_with((string) LingoWP\Language\Domain\FlagResolver::url('eo', '1x1'), '/flags/eo.svg'), (string) LingoWP\Language\Domain\FlagResolver::url('eo', '1x1'));

// ── 11. Open animation ──────────────────────────────────────────────────────

test_section('11. Open animation (dropdown_animation)');

delete_option(SwitcherSettings::OPTION); // back to defaults (rolled back)

$animates = static fn(string $html): bool => (bool) preg_match('#<nav\b[^>]*class="[^"]*\blingowp-switcher--animate\b#s', $html);

test_ok('default: off', !$animates($switcher->render([])));
test_ok('dropdown_animation="true" (shortcode) turns it on', $animates((new SwitcherShortcode($switcher))->shortcode(['dropdown_animation' => 'true'])));
test_ok('list layouts never get it', !$animates($switcher->render(['layout' => 'vertical', 'dropdown_animation' => true])));

SwitcherSettings::update(['dropdown_animation' => true], false);
test_ok('saved on: every dropdown animates', $animates($switcher->render([])));
test_ok('... and dropdown_animation="false" turns it off for one switcher', !$animates((new SwitcherShortcode($switcher))->shortcode(['dropdown_animation' => 'false'])));

wp_set_current_user($adminId);
[$status, $data] = design_rest('POST', ['dropdown_animation' => false]);
test_ok('REST saves dropdown_animation', $status === 200 && ($data['dropdown_animation'] ?? null) === false && SwitcherSettings::get()['dropdown_animation'] === false, "status $status " . wp_json_encode($data));
wp_set_current_user($previousUser);

// ── 12. Admin preview ───────────────────────────────────────────────────────

test_section('12. Admin preview (POST /switcher-settings/preview)');

delete_option(SwitcherSettings::OPTION); // nothing saved (rolled back)

/** POST /switcher-settings/preview as the current user. */
function design_preview(array $body): array
{
    $request = new WP_REST_Request('POST', '/lingowp/v1/switcher-settings/preview');
    $request->set_header('content-type', 'application/json');
    $request->set_body((string) wp_json_encode($body));
    $response = rest_do_request($request);

    return [$response->get_status(), (array) $response->get_data()];
}

wp_set_current_user($adminId);

[$status, $data] = design_preview(['bg_color' => '#123456', 'border_width' => '1', 'layout' => 'vertical', 'text_color' => 'nope;}']);
test_ok('renders ok', $status === 200 && ($data['ok'] ?? null) === true, "status $status");
test_ok('the requested layout, through the real template', str_contains((string) ($data['html'] ?? ''), 'lingowp-switcher--vertical') && str_contains((string) ($data['html'] ?? ''), 'id="lingowp-preview"'));
test_ok('the draft acts as saved: boxed from the draft border', str_contains((string) ($data['html'] ?? ''), 'lingowp-switcher--boxed'));
test_ok('head CSS carries the draft variables', str_contains((string) ($data['head_css'] ?? ''), '--lingowp-switcher-bg:#123456'), (string) ($data['head_css'] ?? ''));
test_ok('invalid draft fields are reported (and left out)', in_array('text_color', (array) ($data['invalid'] ?? []), true) && !str_contains((string) ($data['head_css'] ?? ''), 'nope'));
test_ok('nothing was saved', get_option(SwitcherSettings::OPTION) === false);
test_ok('stylesheet and script URLs for the frame', str_contains((string) ($data['assets']['css'] ?? ''), 'switcher.css') && str_contains((string) ($data['assets']['js'] ?? ''), 'switcher.js'));

[$status, $data] = design_preview([]);
test_ok('defaults: a dropdown with its arrow', str_contains((string) ($data['html'] ?? ''), 'lingowp-switcher--dropdown') && str_contains((string) ($data['html'] ?? ''), 'lingowp-switcher--arrow'));

[$status, $data] = design_preview(['custom_css_enabled' => true, 'custom_css' => '.lingowp-switcher{gap:3px}']);
test_ok('administrator: the draft custom CSS is previewed', str_contains((string) ($data['head_css'] ?? ''), '.lingowp-switcher{gap:3px}'));

add_filter('map_meta_cap', $noUnfiltered, 10, 2);
[$status, $data] = design_preview(['custom_css_enabled' => true, 'custom_css' => '.lingowp-switcher{gap:3px}']);
test_ok('without unfiltered_html: a draft custom CSS is never previewed', !str_contains((string) ($data['head_css'] ?? ''), 'gap:3px'));
remove_filter('map_meta_cap', $noUnfiltered, 10);

wp_set_current_user(0);
[$status] = design_preview([]);
test_ok('logged out: refused', in_array($status, [401, 403], true), "status $status");
wp_set_current_user($previousUser);

// ── 13. Floating switcher ───────────────────────────────────────────────────

test_section('13. Floating switcher');

delete_option(SwitcherSettings::OPTION); // defaults (rolled back)

$footer = static function () use ($switcher): string {
    ob_start();
    $switcher->renderFloating();

    return (string) ob_get_clean();
};
$hasClass = static fn(string $html, string $class): bool => (bool) preg_match('#<nav\b[^>]*class="[^"]*\b' . preg_quote($class, '#') . '\b#s', $html);

test_ok('hooked on wp_footer (the booted instance)', has_action('wp_footer', [Switcher::instance(), 'renderFloating']) !== false);
test_ok('off by default: nothing in the footer', $footer() === '');

SwitcherSettings::update(['floating_enabled' => true], false);
$html = $footer();
test_ok('enabled: a floating dropdown, bottom right by default', $hasClass($html, 'lingowp-switcher--floating') && $hasClass($html, 'lingowp-switcher--floating-right') && $hasClass($html, 'lingowp-switcher--dropdown'), $html === '' ? '(empty)' : '');
test_ok('... always boxed (padding), with its own id', $hasClass($html, 'lingowp-switcher--boxed') && str_contains($html, 'id="lingowp-floating-switcher"'));

SwitcherSettings::update(['floating_position' => 'bottom-left', 'floating_offset' => '40'], false);
$html = $footer();
test_ok('bottom-left corner', $hasClass($html, 'lingowp-switcher--floating-left') && !$hasClass($html, 'lingowp-switcher--floating-right'));
test_ok('the distance from the edges lands in its style', str_contains($html, '--lingowp-switcher-floating-offset:40px'));

SwitcherSettings::update(['button_width' => 'full', 'bg_color' => '#000'], false);
test_ok('never full width, even when saved', !$hasClass($footer(), 'lingowp-switcher--full'));
test_ok('... while an ordinary dropdown still is', $hasClass($switcher->render([]), 'lingowp-switcher--full'));

test_ok('distance validation: 150px rejected, 2em kept', SwitcherSettings::sanitizeValue('floating_offset', '150px') === '' && SwitcherSettings::sanitizeValue('floating_offset', '2em') === '2em');

wp_set_current_user($adminId);
[$status, $data] = design_rest('POST', ['floating_enabled' => false, 'floating_position' => 'bottom-right', 'floating_offset' => '24']);
test_ok('REST saves the floating settings', ($data['ok'] ?? null) === true && ($data['floating_enabled'] ?? null) === false && ($data['floating_position'] ?? '') === 'bottom-right' && ($data['floating_offset'] ?? '') === '24px', wp_json_encode($data));
[$status, $data] = design_rest('POST', ['floating_position' => 'top-left', 'floating_offset' => '9999px']);
test_ok('REST: an unknown corner and a too-large distance are ERR_INVALID_INPUT', ($data['error'] ?? '') === 'ERR_INVALID_INPUT' && in_array('floating_position', (array) ($data['fields'] ?? []), true) && in_array('floating_offset', (array) ($data['fields'] ?? []), true), wp_json_encode($data));

[$status, $data] = design_preview(['floating_enabled' => true, 'floating_position' => 'bottom-left']);
test_ok('preview: the floating switcher when the draft enables it', str_contains((string) ($data['floating_html'] ?? ''), 'lingowp-switcher--floating-left'));
[$status, $data] = design_preview(['floating_enabled' => false]);
test_ok('preview: none when it is off', ($data['floating_html'] ?? 'x') === '');
wp_set_current_user($previousUser);

// ── 14. Uninstall ────────────────────────────────────────────────────────────

test_section('14. Uninstall');

$options = (new ReflectionClassConstant(PluginDataCleanup::class, 'OPTIONS'))->getValue();
test_ok('PluginDataCleanup removes lingowp_switcher_settings', in_array(SwitcherSettings::OPTION, (array) $options, true));

set_query_var('lingowp_lang', '');

test_done();

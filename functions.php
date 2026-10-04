<?php

/**
 * Public template functions for themes and plugins.
 *
 * The supported entry points for rendering a language switcher, reading its
 * data, or (for an add-on plugin like lingowp-acf) reaching the source
 * repository to register discovered content. Everything else in LingoWP is an
 * implementation detail and may change.
 *
 * @package LingoWP
 */

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\LocalizationRouting\LanguageLinks;
use LingoWP\Switcher\Switcher;

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('lingowp_get_language_links')) {
    /**
     * The current page's URL in every enabled language, plus display metadata.
     *
     * Each row:
     *   [
     *     'code'         => 'fr_FR',   // canonical locale
     *     'slug'         => 'fr',      // URL segment
     *     'url'          => 'https://example.com/fr/about/', // absolute; this page in that language
     *     'native_name'  => 'Français',
     *     'english_name' => 'French',
     *     'dir'          => 'ltr',     // 'ltr' | 'rtl'
     *     'region'       => 'FR',      // ISO-3166 region from the locale, or ''
     *     'flag_url'     => 'https://example.com/wp-content/plugins/lingowp/assets/flags/fr.svg', // '' when none maps
     *     'is_source'    => false,
     *     'current'      => true,      // the active request language
     *   ]
     *
     * Values are raw — escape at output. Returns [] when fewer than two
     * languages are enabled. No database query; memoized per request. Filterable
     * via the 'lingowp_language_links' filter.
     *
     * @param array<string, mixed> $args {
     *   @type bool            $hide_current  Drop the active language. Default false.
     *   @type string[]        $include       Keep only these locales, in this order.
     *   @type string|string[] $order         'settings' (default), 'alpha', or an explicit locale list.
     * }
     * @return array<int, array<string, mixed>>
     */
    function lingowp_get_language_links(array $args = []): array
    {
        return LanguageLinks::instance()->links($args);
    }
}

if (!function_exists('lingowp_get_switcher')) {
    /**
     * The language switcher markup as a string.
     *
     * @param array<string, mixed> $args {
     *   @type string          $layout         'dropdown' (default) | 'horizontal' | 'vertical'.
     *   @type string          $label_format   'full' (default) | 'short' | 'none'.
     *   @type string          $name_source    'native' (default) | 'english' | 'current' (the
     *                                          language of the page being viewed).
     *   @type string          $flag_position  'before' (default) | 'after' | 'hidden'.
     *   @type string          $open_on        'click' (default) | 'hover' — dropdown only.
     *   @type bool            $hide_current   Drop the active language from the choices.
     *   @type string          $label          Wrapper aria-label. Default "Language".
     *   @type string          $id             Wrapper id. Default generated.
     *   @type string          $class          Extra CSS classes for the wrapper.
     *   @type string[]        $include        Keep only these locales, in this order.
     *   @type string|string[] $order          'settings' (default), 'alpha', or a locale list.
     * }
     * The previous switcher's display/show, flags and layout="inline|list"
     * arguments are still accepted and mapped onto these.
     */
    function lingowp_get_switcher(array $args = []): string
    {
        return Switcher::instance()->render($args);
    }
}

if (!function_exists('lingowp_source_repository')) {
    /**
     * The booted source repository — the one stable entry point an add-on
     * plugin (lingowp-acf, lingowp-woocommerce, …) uses to register content it
     * discovers, via whichever reconcile*() method matches its source type
     * (e.g. reconcileTermMeta() for a taxonomy term's meta,
     * reconcileOption() for a plugin's own option-backed strings — see
     * lingowp-woocommerce's AttributeLabelSync/TaxRateSync). Everything else
     * on WpDbSourceRepository is available too, but reconcile*() is the
     * supported add-on surface — the rest may change without notice.
     */
    function lingowp_source_repository(): WpDbSourceRepository
    {
        return WpDbSourceRepository::instance();
    }
}

if (!function_exists('lingowp_switcher')) {
    /**
     * Echo the language switcher. See lingowp_get_switcher() for $args.
     *
     * @param array<string, mixed> $args
     */
    function lingowp_switcher(array $args = []): void
    {
        echo lingowp_get_switcher($args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the switcher template escapes every value
    }
}

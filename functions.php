<?php

/**
 * Public template functions for themes and plugins.
 *
 * The supported entry points for rendering a language switcher or reading its
 * data. Everything else in LingoWP is an implementation detail and may change.
 *
 * @package LingoWP
 */

use LingoWP\LocalizationRouting\LanguageLinks;
use LingoWP\LocalizationRouting\SwitcherRenderer;

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
     * The bundled language switcher markup as a string.
     *
     * @param array<string, mixed> $args {
     *   @type string $display       'native' (default) | 'english' | 'code'.
     *   @type string $layout        'dropdown' (default) | 'inline' | 'list'.
     *   @type bool   $hide_current  Drop the active language. Default false.
     *   @type string $class         Extra CSS classes for the wrapper.
     * }
     */
    function lingowp_get_switcher(array $args = []): string
    {
        return SwitcherRenderer::instance()->render($args);
    }
}

if (!function_exists('lingowp_switcher')) {
    /**
     * Echo the bundled language switcher. See lingowp_get_switcher() for $args.
     *
     * @param array<string, mixed> $args
     */
    function lingowp_switcher(array $args = []): void
    {
        echo lingowp_get_switcher($args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SwitcherRenderer escapes every value
    }
}

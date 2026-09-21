<?php

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\LocalizationRouting\LanguageLinks;
use LingoWP\LocalizationRouting\SwitcherRenderer;

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('lingowp_get_language_links')) {
    function lingowp_get_language_links(array $args = []): array
    {
        return LanguageLinks::instance()->links($args);
    }
}

if (!function_exists('lingowp_get_switcher')) {
    function lingowp_get_switcher(array $args = []): string
    {
        return SwitcherRenderer::instance()->render($args);
    }
}

if (!function_exists('lingowp_source_repository')) {
    function lingowp_source_repository(): WpDbSourceRepository
    {
        return WpDbSourceRepository::instance();
    }
}

if (!function_exists('lingowp_switcher')) {
    function lingowp_switcher(array $args = []): void
    {
        echo lingowp_get_switcher($args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SwitcherRenderer escapes every value
    }
}

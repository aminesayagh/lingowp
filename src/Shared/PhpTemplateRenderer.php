<?php

namespace LingoWP\Shared;

/**
 * Minimal template renderer.
 *
 * Keeps PHP logic out of markup files. Pass a template name (relative to
 * templates/) and an array of variables; they become local vars inside it.
 */
class PhpTemplateRenderer
{
    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = []): void
    {
        $file = LINGOWP_DIR . 'templates/' . ltrim($template, '/');

        if (!is_readable($file)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                trigger_error( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- WP_DEBUG only: a missing template is a packaging bug.
                    sprintf('Linvara template not found: %s', esc_html($template)),
                    E_USER_WARNING
                );
            }

            return;
        }

        extract($data, EXTR_SKIP);
        require $file;
    }
}

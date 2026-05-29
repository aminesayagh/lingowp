<?php

namespace TransLangs\Support;

/**
 * Minimal template renderer.
 *
 * Keeps PHP logic out of markup files. Pass a template name (relative to
 * templates/) and an array of variables; they become local vars inside it.
 */
class View
{
    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = []): void
    {
        $file = TRANSLANGS_DIR . 'templates/' . ltrim($template, '/');

        if (!file_exists($file)) {
            return;
        }

        extract($data, EXTR_SKIP);
        require $file;
    }
}

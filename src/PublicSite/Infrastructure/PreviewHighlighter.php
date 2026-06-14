<?php

namespace TransLangs\PublicSite\Infrastructure;

use TransLangs\TranslationMemory\Infrastructure\Repositories\AdminBoardQuery;

/**
 * Front-end companion to the admin "Preview on site" action. When a page is
 * opened with ?translangs_preview=<lang>&translangs_focus=<key> by a logged-in
 * editor, this enqueues a small script that locates the previewed string on the
 * page (by CSS selector for HTML templates, otherwise by its translated text)
 * and highlights it. Read-only and editor-gated, like the preview language
 * switch itself (ResolveRequestLanguage::resolveFromPreview).
 */
final class PreviewHighlighter
{
    public function __construct(private AdminBoardQuery $query) {}

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        if (is_admin()) {
            return;
        }

        $lang  = isset($_GET['translangs_preview']) ? sanitize_key(wp_unslash($_GET['translangs_preview'])) : '';
        $focus = isset($_GET['translangs_focus']) ? strtolower((string) wp_unslash($_GET['translangs_focus'])) : '';
        if ($lang === '' || $focus === '' || !preg_match('/^[0-9a-f]{64}$/', $focus)) {
            return;
        }

        // Editor-gated: the focus key only reveals already-visible page text.
        if (!function_exists('current_user_can') || !current_user_can('edit_posts')) {
            return;
        }

        $row = $this->query->loadString((string) hex2bin($focus), $lang);
        if ($row === null) {
            return;
        }

        $selector = (int) $row['type'] === 5 ? (string) $row['ref1'] : '';
        $text     = (string) ($row['translated_text'] ?? '');
        if ($text === '') {
            // Not translated yet — still locate the spot via the original text.
            $text = (string) $row['original_text'];
        }

        wp_register_script('translangs-preview-highlight', TRANSLANGS_URL . 'assets/public/preview-highlight.js', [], TRANSLANGS_VERSION, true);
        wp_register_style('translangs-preview-highlight', TRANSLANGS_URL . 'assets/public/preview-highlight.css', [], TRANSLANGS_VERSION);

        wp_add_inline_script(
            'translangs-preview-highlight',
            'window.TranslangsPreview=' . wp_json_encode(['selector' => $selector, 'text' => $text]) . ';',
            'before'
        );

        wp_enqueue_style('translangs-preview-highlight');
        wp_enqueue_script('translangs-preview-highlight');
    }
}

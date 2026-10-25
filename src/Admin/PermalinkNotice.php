<?php

namespace LingoWP\Admin;

use LingoWP\Language\Infrastructure\OptionLanguageRegistry;

/**
 * Language-prefix routing needs pretty permalinks: the prefixed rewrite rules
 * are cloned from WordPress's own, and "Plain" permalinks have none to clone.
 * Rather than ship a switcher whose target links 404, warn on the plugin's own
 * screens and point at Settings → Permalinks.
 */
class PermalinkNotice
{
    private OptionLanguageRegistry $registry;

    public function __construct(OptionLanguageRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'maybeRender']);
    }

    public function maybeRender(): void
    {
        if ((string) get_option('permalink_structure', '') !== '') {
            return;
        }

        if ($this->registry->getTargetLanguages() === []) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id     = is_object($screen) ? (string) ($screen->id ?? '') : '';
        if ($id !== 'toplevel_page_lingowp' && strpos($id, 'lingowp_page_') !== 0) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('Lingostack needs pretty permalinks for language-prefixed URLs. Your site is using “Plain” permalinks, so translated pages will not resolve.', 'lingostack'),
            esc_url(admin_url('options-permalink.php')),
            esc_html__('Open Permalink Settings', 'lingostack')
        );
    }
}

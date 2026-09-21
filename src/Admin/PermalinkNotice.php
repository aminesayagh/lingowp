<?php

namespace LingoWP\Admin;

use LingoWP\Language\Infrastructure\OptionLanguageRegistry;

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
            esc_html__('LingoWP needs pretty permalinks for language-prefixed URLs. Your site is using “Plain” permalinks, so translated pages will not resolve.', 'lingowp'),
            esc_url(admin_url('options-permalink.php')),
            esc_html__('Open Permalink Settings', 'lingowp')
        );
    }
}

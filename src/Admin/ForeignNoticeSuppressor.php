<?php

namespace LingoWP\Admin;

final class ForeignNoticeSuppressor
{
    private const HOOKS = ['admin_notices', 'all_admin_notices', 'user_admin_notices'];

    public function register(): void
    {
        add_action('in_admin_header', [$this, 'strip']);
    }

    public function strip(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id     = $screen ? (string) $screen->id : '';

        if ($id !== 'toplevel_page_lingowp' && strpos($id, 'lingowp_page_') !== 0) {
            return;
        }

        global $wp_filter;

        foreach (self::HOOKS as $hook) {
            if (empty($wp_filter[$hook])) {
                continue;
            }

            foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $handle) {
                    if (!$this->isLingoWp($handle['function'])) {
                        remove_action($hook, $handle['function'], $priority);
                    }
                }
            }
        }
    }

    private function isLingoWp($callback): bool
    {
        if (is_array($callback)) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];

            return strncmp($class, 'LingoWP', 7) === 0;
        }

        if (is_string($callback)) {
            return strncmp($callback, 'LingoWP', 7) === 0;
        }

        return true;
    }
}

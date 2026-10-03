<?php

namespace LingoWP\Admin;

/**
 * Strips third-party admin notices (Elementor upgrade nags, core update
 * pointers, other plugins) from the LingoWP SPA screens, where they break
 * the React layout. LingoWP's own notices are left in place.
 */
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

    /**
     * @param callable|string|array $callback
     */
    private function isLingoWp($callback): bool
    {
        if (is_array($callback)) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];

            return strncmp($class, 'LingoWP', 7) === 0;
        }

        if (is_string($callback)) {
            return strncmp($callback, 'LingoWP', 7) === 0;
        }

        // ponytail: closures kept as-is — LingoWP registers a couple, foreign
        // plugins almost never use them for notices. Tighten only if one slips through.
        return true;
    }
}

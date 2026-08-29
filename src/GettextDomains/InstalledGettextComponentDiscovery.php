<?php

namespace LingoWP\GettextDomains;

/**
 * Every installed plugin/theme's textdomain → {label, kind, slug, version,
 * path}, built fresh per request (cheap: a handful of plugin headers + the
 * active theme). Domains with no match are simply absent — "Other" bucketing
 * is the caller's job.
 *
 * Extracted out of CollectionsRestController (which originally only needed
 * label/kind for its "Plugins & Themes" board) so the gettext translation-file
 * screen (which additionally needs slug/version for an online lookup, and
 * path for scanning source) can share one discovery pass instead of one REST
 * controller reaching into another's internals.
 */
final class InstalledGettextComponentDiscovery
{
    /** @return array<string, array{label: string, kind: 'plugin'|'theme', slug: string, version: string, path: string}> */
    public function all(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $map = [];

        foreach (get_plugins() as $pluginFile => $data) {
            // LingoWP itself is never a translation target on its own board.
            if ($pluginFile === LINGOWP_BASENAME) {
                continue;
            }

            // No TextDomain header: fall back to the plugin's own folder slug,
            // which is what WordPress itself uses to guess a domain at load time.
            $dir    = dirname($pluginFile);
            $dir    = $dir === '.' ? '' : $dir;
            $domain = trim((string) ($data['TextDomain'] ?? ''));
            if ($domain === '') {
                $domain = $dir;
            }
            if ($domain === '') {
                continue;
            }

            $map[$domain] = [
                'label'   => (string) ($data['Name'] ?: $domain),
                'kind'    => 'plugin',
                'slug'    => $dir !== '' ? $dir : $domain,
                'version' => (string) ($data['Version'] ?? ''),
                // Empty for a single-file plugin (no subfolder to scan) —
                // callers that need a source directory to walk must check.
                'path'    => $dir !== '' ? WP_PLUGIN_DIR . '/' . $dir : '',
            ];
        }

        $theme = wp_get_theme();
        foreach (array_filter([$theme, $theme->parent()]) as $t) {
            $slug   = $t->get_stylesheet();
            $domain = trim((string) $t->get('TextDomain'));
            if ($domain === '') {
                $domain = $slug;
            }
            if ($domain === '') {
                continue;
            }

            $map[$domain] = [
                'label'   => (string) $t->get('Name'),
                'kind'    => 'theme',
                'slug'    => $slug,
                'version' => (string) $t->get('Version'),
                'path'    => $t->get_stylesheet_directory(),
            ];
        }

        return $map;
    }
}

<?php

namespace LingoWP\GettextDomains;

final class InstalledGettextComponentDiscovery
{
    public function all(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $map = [];

        foreach (get_plugins() as $pluginFile => $data) {
            if ($pluginFile === LINGOWP_BASENAME) {
                continue;
            }

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

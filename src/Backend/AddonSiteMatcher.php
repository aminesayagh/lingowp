<?php

namespace LingoWP\Backend;

final class AddonSiteMatcher
{
    private const PENDING_OPTION = 'lingowp_pending_addon_activations';

    public function register(): void
    {
        add_action('activated_plugin', [$this, 'onPluginActivated'], 10, 1);
        add_action('switch_theme', [$this, 'onThemeSwitched'], 10, 2);
    }

    public function isRelevant(array $detects): bool
    {
        return self::matches($detects, $this->activePlugins(), (string) wp_get_theme()->get_template());
    }

    public static function matches(array $detects, array $activePlugins, string $templateSlug): bool
    {
        if ($detects === []) {
            return true;
        }

        foreach ($detects as $detect) {
            $detect = (string) $detect;
            if (strpos($detect, 'theme:') === 0) {
                if (substr($detect, 6) === $templateSlug) {
                    return true;
                }
                continue;
            }
            if (in_array($detect, $activePlugins, true)) {
                return true;
            }
        }

        return false;
    }

    public function isInstalled(string $product): bool
    {
        return in_array("lingowp-{$product}/lingowp-{$product}.php", $this->activePlugins(), true);
    }

    public function onPluginActivated(string $plugin): void
    {
        $this->remember($plugin);
    }

    public function onThemeSwitched(string $newName, $newTheme): void
    {
        $template = $newTheme instanceof \WP_Theme ? $newTheme->get_template() : wp_get_theme()->get_template();
        $this->remember('theme:' . $template);
    }

    public function takePendingActivations(): array
    {
        $pending = get_option(self::PENDING_OPTION, []);
        if ($pending !== []) {
            delete_option(self::PENDING_OPTION);
        }

        return is_array($pending) ? array_values($pending) : [];
    }

    private function remember(string $detect): void
    {
        $pending = get_option(self::PENDING_OPTION, []);
        $pending = is_array($pending) ? $pending : [];
        if (in_array($detect, $pending, true)) {
            return;
        }
        $pending[] = $detect;
        update_option(self::PENDING_OPTION, $pending, false);
    }

    private function activePlugins(): array
    {
        $plugins = get_option('active_plugins', []);
        if (is_multisite()) {
            $plugins = array_merge($plugins, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }

        return array_values(array_map('strval', (array) $plugins));
    }
}

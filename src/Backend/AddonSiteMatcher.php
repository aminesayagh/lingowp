<?php

namespace LingoWP\Backend;

/**
 * Answers "does this add-on matter on THIS site" for the tier-based add-on
 * catalog (GET /billing/addons). The backend ships each add-on with a
 * `detects` list — plugin basenames for is_plugin_active() or
 * "theme:<template-slug>" for builder themes — and the WP side is the only
 * place that can check them. Also records third-party plugin activations /
 * theme switches so the admin UI can toast "X detected — install the X
 * add-on" exactly once, on the next LingoWP screen load.
 */
final class AddonSiteMatcher
{
    private const PENDING_OPTION = 'lingowp_pending_addon_activations';

    public function register(): void
    {
        add_action('activated_plugin', [$this, 'onPluginActivated'], 10, 1);
        add_action('switch_theme', [$this, 'onThemeSwitched'], 10, 2);
    }

    /** Empty `detects` means a LingoWP-native tool — always relevant. */
    public function isRelevant(array $detects): bool
    {
        return self::matches($detects, $this->activePlugins(), (string) wp_get_theme()->get_template());
    }

    /**
     * Pure comparison, separated so it can be checked without WordPress.
     * Theme entries only match the template slug; a plugin basename that
     * happens to equal a theme slug must not cross over.
     */
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

    /** Every add-on zip unpacks to lingowp-<product>/lingowp-<product>.php (backend catalog contract). */
    public function isInstalled(string $product): bool
    {
        return in_array("lingowp-{$product}/lingowp-{$product}.php", $this->activePlugins(), true);
    }

    public function onPluginActivated(string $plugin): void
    {
        $this->remember($plugin);
    }

    /** @param \WP_Theme $newTheme the hook's second argument — the theme just switched to. */
    public function onThemeSwitched(string $newName, $newTheme): void
    {
        $template = $newTheme instanceof \WP_Theme ? $newTheme->get_template() : wp_get_theme()->get_template();
        $this->remember('theme:' . $template);
    }

    /** Read-and-clear: a lost toast is fine, the Integrations tab still lists the add-on. */
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

    /** @return string[] */
    private function activePlugins(): array
    {
        $plugins = get_option('active_plugins', []);
        if (is_multisite()) {
            $plugins = array_merge($plugins, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }

        return array_values(array_map('strval', (array) $plugins));
    }
}

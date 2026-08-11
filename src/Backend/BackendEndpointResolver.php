<?php

namespace TransLangs\Backend;

/**
 * Resolves the Translangs backend base URL and provision key.
 *
 * This plugin is distributed publicly, so nothing here can depend on a site
 * owner editing wp-config.php — resolution is self-contained in the plugin.
 * The baked-in DEFAULT_ORIGIN ships with the plugin and requires zero
 * site-owner configuration; the filter/option remain only as internal
 * dev/support overrides. The "/api/v1" version path is code, not
 * user-editable.
 */
final class BackendEndpointResolver
{
    public const OPTION_ORIGIN = 'translangs_api_origin';

    private const VERSION_PATH = '/api/v1';

    // ponytail: dev placeholder — swap for the real production origin before release.
    private const DEFAULT_ORIGIN = 'http://localhost:8000';

    /** Full API base, e.g. https://api.example.com/api/v1. */
    public function baseUrl(): string
    {
        return $this->origin() . self::VERSION_PATH;
    }

    public function origin(): string
    {
        // 1. Filter override (programmatic — mu-plugin/hosting integration).
        $filtered = apply_filters('translangs_api_origin', '');
        if (is_string($filtered) && $filtered !== '') {
            return $this->normalize($filtered);
        }

        // 2. Stored option (dev/advanced-only support screen).
        $option = (string) get_option(self::OPTION_ORIGIN, '');
        if ($option !== '') {
            return $this->normalize($option);
        }

        // 3. Baked-in default — every public install works out of the box.
        return self::DEFAULT_ORIGIN;
    }

    /** Server-only provision key for the first POST /auth/site. */
    public function provisionKey(): string
    {
        if (defined('TRANSLANGS_PROVISION_KEY') && is_string(TRANSLANGS_PROVISION_KEY)) {
            return (string) TRANSLANGS_PROVISION_KEY;
        }

        $filtered = apply_filters('translangs_provision_key', '');

        return is_string($filtered) ? $filtered : '';
    }

    /** Dev-only reset tool: drop back to the filter/baked-in default. */
    public function resetOrigin(): void
    {
        delete_option(self::OPTION_ORIGIN);
    }

    private function normalize(string $origin): string
    {
        return untrailingslashit(trim($origin));
    }
}

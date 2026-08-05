<?php

namespace TransLangs\Backend;

/**
 * Resolves the Translangs backend base URL and provision key.
 *
 * The origin is configurable (production origin is not finalized yet); the
 * "/api/v1" version path is code, not user-editable. The provision key is a
 * server-only secret — it is read from a constant or filter and is never exposed
 * to the browser, localized scripts, or REST responses.
 */
final class BackendEndpointResolver
{
    public const OPTION_ORIGIN = 'translangs_api_origin';

    private const VERSION_PATH     = '/api/v1';
    private const LOCALHOST_ORIGIN = 'http://localhost:3002';

    /** Full API base, e.g. https://api.example.com/api/v1. Empty if unresolved. */
    public function baseUrl(): string
    {
        $origin = $this->origin();

        return $origin === '' ? '' : $origin . self::VERSION_PATH;
    }

    public function origin(): string
    {
        // 1. Hard constant in wp-config.php wins (per-environment, not editable in UI).
        if (defined('TRANSLANGS_API_ORIGIN') && is_string(TRANSLANGS_API_ORIGIN)) {
            return $this->normalize((string) TRANSLANGS_API_ORIGIN);
        }

        // 2. Filter override (programmatic).
        $filtered = apply_filters('translangs_api_origin', '');
        if (is_string($filtered) && $filtered !== '') {
            return $this->normalize($filtered);
        }

        // 3. Stored option (dev/advanced only).
        $option = (string) get_option(self::OPTION_ORIGIN, '');
        if ($option !== '') {
            return $this->normalize($option);
        }

        // 4. Localhost fallback only while debugging.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return self::LOCALHOST_ORIGIN;
        }

        return '';
    }

    /** Server-only provision key for /auth/categories and first /auth/site. */
    public function provisionKey(): string
    {
        if (defined('TRANSLANGS_PROVISION_KEY') && is_string(TRANSLANGS_PROVISION_KEY)) {
            return (string) TRANSLANGS_PROVISION_KEY;
        }

        $filtered = apply_filters('translangs_provision_key', '');

        return is_string($filtered) ? $filtered : '';
    }

    /** Dev-only reset tool: drop back to the constant/filter/localhost defaults. */
    public function resetOrigin(): void
    {
        delete_option(self::OPTION_ORIGIN);
    }

    private function normalize(string $origin): string
    {
        return untrailingslashit(trim($origin));
    }
}

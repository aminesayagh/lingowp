<?php

namespace TransLangs\Api;

/**
 * Stores the backend connection state in wp_options.
 *
 * The token and site id are secrets: they are written with autoload=false so
 * they never ride along on every front-end request, and they are never returned
 * to the browser (the REST layer strips them). The onboarding-completed flag is
 * not a secret and is seeded (autoloaded) at activation.
 */
final class ConnectionRepository
{
    public const OPTION_TOKEN     = 'translangs_api_token';
    public const OPTION_SITE_ID   = 'translangs_site_id';
    public const OPTION_COMPLETED = 'translangs_onboarding_completed';

    public function isConnected(): bool
    {
        return $this->token() !== '';
    }

    public function token(): string
    {
        return (string) get_option(self::OPTION_TOKEN, '');
    }

    public function siteId(): string
    {
        return (string) get_option(self::OPTION_SITE_ID, '');
    }

    public function store(string $siteId, string $token): void
    {
        update_option(self::OPTION_SITE_ID, $siteId, false);
        update_option(self::OPTION_TOKEN, $token, false);
    }

    /** Clears local credentials. Used on disconnect, even if remote revoke fails. */
    public function clear(): void
    {
        delete_option(self::OPTION_TOKEN);
        delete_option(self::OPTION_SITE_ID);
    }

    public function isOnboardingComplete(): bool
    {
        return (bool) get_option(self::OPTION_COMPLETED, false);
    }

    public function markOnboardingComplete(): void
    {
        update_option(self::OPTION_COMPLETED, true);
    }
}

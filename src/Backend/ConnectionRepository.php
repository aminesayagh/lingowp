<?php

namespace TransLangs\Backend;

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

    /** Canonical host this site presents to the backend — must match exactly what was sent at issue time. */
    public function domain(): string
    {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    /**
     * Display-only, never the real key: a fixed placeholder plus the last 4
     * real characters (Stripe/GitHub-style) — everything else is constant,
     * independent of the actual stored value, so there is nothing here an
     * attacker could use even if screenshotted or logged. 'tl_live_' is the
     * real, confirmed prefix every issued key uses (backend's
     * LICENSE_KEY_PREFIX), not a guess.
     */
    public function maskedKey(): string
    {
        $token = $this->token();
        if ($token === '') {
            return '';
        }

        return 'tl_live_' . str_repeat('•', 16) . substr($token, -4);
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

    /**
     * Full wipe for the dev reset tool: credentials AND the onboarding-completed
     * flag, so the site looks like a genuine first activation. Unlike clear()
     * (a normal disconnect), onboarding-completed is not preserved here.
     */
    public function forgetAll(): void
    {
        $this->clear();
        delete_option(self::OPTION_COMPLETED);
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

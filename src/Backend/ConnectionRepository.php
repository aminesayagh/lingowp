<?php

namespace LingoWP\Backend;

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
    public const OPTION_TOKEN     = 'lingowp_api_token';
    public const OPTION_SITE_ID   = 'lingowp_site_id';
    public const OPTION_COMPLETED = 'lingowp_onboarding_completed';
    public const OPTION_OWNER_EMAIL = 'lingowp_owner_email';
    public const OPTION_SYNCED_ADMIN_EMAIL = 'lingowp_synced_admin_email';
    public const OPTION_CLOUD_OPT_OUT = 'lingowp_cloud_opt_out';

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
     * attacker could use even if screenshotted or logged. 'lingowp_live_' is
     * the LingoWP-branded prefix (renamed from the legacy 'tl_live_') —
     * the backend's LICENSE_KEY_PREFIX must be updated to match, or this
     * mask will misrepresent real issued keys until it is.
     */
    public function maskedKey(): string
    {
        $token = $this->token();
        if ($token === '') {
            return '';
        }

        return 'lingowp_live_' . str_repeat('•', 16) . substr($token, -4);
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
     * Full wipe for the dev reset tool: credentials, the onboarding-completed
     * flag, AND the cloud opt-out flag, so the site looks like a genuine first
     * activation. Unlike clear() (a normal disconnect, which sets opt-out),
     * nothing here is preserved.
     */
    public function forgetAll(): void
    {
        $this->clear();
        delete_option(self::OPTION_COMPLETED);
        $this->setOptedOut(false);
    }

    public function isOnboardingComplete(): bool
    {
        return (bool) get_option(self::OPTION_COMPLETED, false);
    }

    public function markOnboardingComplete(): void
    {
        update_option(self::OPTION_COMPLETED, true);
    }

    /**
     * There is no backend read endpoint for the licence's owner_email yet —
     * only the write (POST /owner/assign-owner) and its confirm callback.
     * This site's own confirm response is the only moment we ever learn the
     * verified address, so it's cached locally here (same pattern as
     * store(), which does the equivalent for the license credential) rather
     * than the SPA re-asking the backend something it has no route for.
     */
    public function ownerEmail(): string
    {
        return (string) get_option(self::OPTION_OWNER_EMAIL, '');
    }

    public function isOwnerEmailVerified(): bool
    {
        return $this->ownerEmail() !== '';
    }

    public function storeVerifiedOwnerEmail(string $email): void
    {
        update_option(self::OPTION_OWNER_EMAIL, $email);
    }

    /** The WP admin_email value this site last successfully pushed to the backend. */
    public function syncedAdminEmail(): string
    {
        return (string) get_option(self::OPTION_SYNCED_ADMIN_EMAIL, '');
    }

    public function storeSyncedAdminEmail(string $email): void
    {
        update_option(self::OPTION_SYNCED_ADMIN_EMAIL, $email);
    }

    /**
     * Whether the admin has explicitly disconnected (Billing → Danger zone).
     * Gates SiteConnector::ensureValid()'s auto-connect: an opted-out site is
     * never contacted again on its own, only via an explicit "Connect" click,
     * which clears this flag.
     */
    public function isOptedOut(): bool
    {
        return (bool) get_option(self::OPTION_CLOUD_OPT_OUT, false);
    }

    public function setOptedOut(bool $optedOut): void
    {
        if ($optedOut) {
            update_option(self::OPTION_CLOUD_OPT_OUT, true);
            return;
        }

        delete_option(self::OPTION_CLOUD_OPT_OUT);
    }
}

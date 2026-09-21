<?php

namespace LingoWP\Backend;

final class ConnectionRepository
{
    public const OPTION_TOKEN     = 'lingowp_api_token';
    public const OPTION_SITE_ID   = 'lingowp_site_id';
    public const OPTION_COMPLETED = 'lingowp_onboarding_completed';
    public const OPTION_OWNER_EMAIL = 'lingowp_owner_email';
    public const OPTION_SYNCED_ADMIN_EMAIL = 'lingowp_synced_admin_email';

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

    public function domain(): string
    {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

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

    public function clear(): void
    {
        delete_option(self::OPTION_TOKEN);
        delete_option(self::OPTION_SITE_ID);
    }

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

    public function syncedAdminEmail(): string
    {
        return (string) get_option(self::OPTION_SYNCED_ADMIN_EMAIL, '');
    }

    public function storeSyncedAdminEmail(string $email): void
    {
        update_option(self::OPTION_SYNCED_ADMIN_EMAIL, $email);
    }
}

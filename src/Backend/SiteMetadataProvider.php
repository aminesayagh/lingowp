<?php

namespace LingoWP\Backend;

use LingoWP\Language\Domain\LanguageRegistry;

/**
 * Builds the site payload sent to POST /license/connect at call time.
 *
 * admin_email is WordPress's own site contact (get_option('admin_email')) —
 * the recovery contact for this domain, not a new onboarding field.
 * default_language is intentionally omitted for now (dropped from the
 * outgoing payload while the backend's handling of it is unsettled) — the
 * LanguageRegistry dependency stays wired so it's a one-line change to add
 * the key back. There is no update endpoint post-issue in this version, so
 * there is nothing to build for a later sync.
 *
 * lingowp_categories is unrelated to the site-connect payload — it is a
 * local-only selection read by the /translate flow (AiTaskSubmitter,
 * GettextDomainRestController) for style context, kept here purely as
 * storage.
 */
final class SiteMetadataProvider
{
    public const OPTION_CATEGORIES = 'lingowp_categories';

    private LanguageRegistry $languages;
    private ConnectionRepository $connection;

    public function __construct(
        LanguageRegistry $languages,
        ConnectionRepository $connection
    ) {
        $this->languages  = $languages;
        $this->connection = $connection;
    }

    /** @return array{domain: string, admin_email: string} */
    public function buildForCreate(): array
    {
        return [
            'domain'      => $this->connection->domain(),
            'admin_email' => (string) get_option('admin_email', ''),
        ];
    }

    /** @return string[] */
    public function categories(): array
    {
        $stored = get_option(self::OPTION_CATEGORIES, []);

        return is_array($stored) ? array_values(array_map('strval', $stored)) : [];
    }

    /** Dev-only reset tool: drop the stored category selection. */
    public function resetCategories(): void
    {
        delete_option(self::OPTION_CATEGORIES);
    }
}

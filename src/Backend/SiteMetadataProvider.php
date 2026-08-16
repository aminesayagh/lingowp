<?php

namespace TransLangs\Backend;

use TransLangs\Language\Domain\LanguageRegistry;

/**
 * Builds the site payload sent to POST /license/issue at call time.
 *
 * The backend takes {domain, admin_email, default_language}. admin_email is
 * WordPress's own site contact (get_option('admin_email')) — the recovery
 * contact for this domain, not a new onboarding field. default_language is
 * the frozen source locale (translangs_source_locale), never onboarding
 * input. There is no update endpoint post-issue in this version, so there is
 * nothing to build for a later sync.
 *
 * translangs_categories is unrelated to the site-connect payload — it is a
 * local-only selection read by the /translate flow (AiTranslationRunner,
 * GettextDomainRestController) for style context, kept here purely as
 * storage.
 */
final class SiteMetadataProvider
{
    public const OPTION_CATEGORIES = 'translangs_categories';

    public function __construct(
        private LanguageRegistry $languages,
        private ConnectionRepository $connection
    ) {}

    /** @return array{domain: string, admin_email: string, default_language: string} */
    public function buildForCreate(): array
    {
        return [
            'domain'           => $this->connection->domain(),
            'admin_email'      => (string) get_option('admin_email', ''),
            'default_language' => $this->languages->getDefaultLanguage(),
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

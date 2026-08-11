<?php

namespace TransLangs\Backend;

use TransLangs\Language\Domain\LanguageRegistry;

/**
 * Builds the site payloads sent to POST/PUT /auth/site at call time.
 *
 * The backend only accepts a minimal shape per verb: POST /auth/site takes
 * {email, domain, default_language}; PUT /auth/site takes {default_language}
 * only. default_language is the frozen source locale
 * (translangs_source_locale), never onboarding input.
 *
 * translangs_categories is unrelated to the site-connect payload — it is a
 * local-only selection read by the /translate flow (AiTranslationRunner,
 * GettextDomainRestController) for style context, kept here purely as
 * storage.
 */
final class SiteMetadataProvider
{
    public const OPTION_CATEGORIES = 'translangs_categories';

    public function __construct(private LanguageRegistry $languages) {}

    /** @return array{email: string, domain: string, default_language: string} */
    public function buildForCreate(): array
    {
        return [
            'email'            => (string) get_option('admin_email', ''),
            'domain'           => (string) wp_parse_url(home_url(), PHP_URL_HOST),
            'default_language' => $this->languages->getDefaultLanguage(),
        ];
    }

    /** @return array{default_language: string} */
    public function buildForSync(): array
    {
        return [
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

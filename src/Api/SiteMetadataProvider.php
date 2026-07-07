<?php

namespace TransLangs\Api;

use TransLangs\Language\Domain\LanguageRegistry;

/**
 * Builds the site payload sent to POST/PUT /auth/site at call time.
 *
 * default_language is the frozen source locale (translangs_source_locale), never
 * onboarding input. active_languages starts empty and grows as target languages
 * are enabled.
 *
 * Categories: the catalog comes from GET /auth/categories, but the user's
 * selection is stored locally (translangs_categories) as pending config so
 * onboarding can complete offline (v1.1 resilience). The stored selection is
 * always included, so the first connect and every later sync carry it.
 */
final class SiteMetadataProvider
{
    public const OPTION_CATEGORIES = 'translangs_categories';

    public function __construct(private LanguageRegistry $languages) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        return [
            'email'            => (string) get_option('admin_email', ''),
            'domain'           => (string) wp_parse_url(home_url(), PHP_URL_HOST),
            'title'            => (string) get_option('blogname', ''),
            'description'      => (string) get_option('blogdescription', ''),
            'tagline'          => (string) get_option('blogdescription', ''),
            'categories'       => $this->categories(),
            'default_language' => $this->languages->getDefaultLanguage(),
            'active_languages' => array_values($this->languages->getTargetLanguages()),
            'active_plugins'   => $this->activePlugins(),
            'active_theme'     => $this->activeTheme(),
        ];
    }

    /** @return string[] */
    public function categories(): array
    {
        $stored = get_option(self::OPTION_CATEGORIES, []);

        return is_array($stored) ? array_values(array_map('strval', $stored)) : [];
    }

    /** @return string[] */
    private function activePlugins(): array
    {
        return array_values(array_map('strval', (array) get_option('active_plugins', [])));
    }

    /** @return array<string, string> */
    private function activeTheme(): array
    {
        $theme = wp_get_theme();

        return [
            'name'       => (string) $theme->get('Name'),
            'stylesheet' => (string) $theme->get_stylesheet(),
            'version'    => (string) $theme->get('Version'),
        ];
    }
}

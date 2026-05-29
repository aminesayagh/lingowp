<?php

namespace TransLangs\Frontend;

/**
 * Decides the active language for the current request.
 *
 * Priority (Phase 4): URL prefix > admin preview param > cookie > browser > default.
 * The URL always wins for public pages; the cookie is only a preference.
 */
class LanguageResolver
{
    public function resolve(): string
    {
        $urlLang = $this->resolveFromUrl();

        if ($urlLang !== null) {
            return $urlLang;
        }

        return $this->getDefaultLanguage();
    }

    public function getDefaultLanguage(): string
    {
        return (string) get_option('translangs_default_language', 'en');
    }

    /**
     * @return string[]
     */
    public function getEnabledLanguages(): array
    {
        return (array) get_option('translangs_enabled_languages', ['en', 'fr']);
    }

    public function isEnabledLanguage(string $lang): bool
    {
        return in_array($lang, $this->getEnabledLanguages(), true);
    }

    public function isTranslatedRequest(): bool
    {
        return $this->resolve() !== $this->getDefaultLanguage();
    }

    private function resolveFromUrl(): ?string
    {
        $lang = get_query_var('translangs_lang');

        if (empty($lang) || !$this->isEnabledLanguage($lang)) {
            return null;
        }

        return sanitize_key($lang);
    }
}

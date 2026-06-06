<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;

/**
 * The one place that owns the "discover once → create rows for all enabled
 * non-default languages" rule.
 *
 * source_lang is never passed to the repository — it is read from get_locale()
 * internally. Discovery only fans out to TARGET languages; rows for the source
 * language are never created.
 */
class DiscoverSourceStrings
{
    public function __construct(
        private TranslationMemoryRepository $repository,
        private ResolveRequestLanguage $languages
    ) {}

    /**
     * Discover the given identities into every active target language.
     *
     * @param TranslationSourceIdentity[] $identities
     * @return int Total rows newly inserted across all target languages.
     */
    public function discover(array $identities): int
    {
        if ($identities === []) {
            return 0;
        }

        $sourceLang = $this->languages->getDefaultLanguage();
        $targets    = $this->targetLanguages($sourceLang);

        if ($targets === []) {
            return 0;
        }

        $inserted = 0;

        foreach ($targets as $targetLang) {
            $inserted += $this->repository->insertMissingStrings($identities, $targetLang);
        }

        return $inserted;
    }

    /**
     * Discover into a single explicit target language.
     * Returns 0 without inserting if $targetLang equals the source language.
     *
     * @param TranslationSourceIdentity[] $identities
     */
    public function discoverForLanguage(array $identities, string $targetLang): int
    {
        if ($identities === [] || $targetLang === '') {
            return 0;
        }

        if ($targetLang === $this->languages->getDefaultLanguage()) {
            return 0;
        }

        return $this->repository->insertMissingStrings($identities, $targetLang);
    }

    /**
     * @return string[]
     */
    private function targetLanguages(string $sourceLang): array
    {
        return array_values(array_filter(
            $this->languages->getEnabledLanguages(),
            static fn(string $lang): bool => $lang !== $sourceLang
        ));
    }
}

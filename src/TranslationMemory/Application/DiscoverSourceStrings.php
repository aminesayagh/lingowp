<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;

/**
 * The one place that owns the "discover once → create rows for all enabled
 * non-default languages" rule.
 *
 * Every discovery path funnels through here:
 *   - the page pipeline (rendered-HTML auditor), and
 *   - the structured source readers (media, taxonomy, post/product/CPT — later)
 *
 * Input is always a list of TranslationSourceIdentity (source_id, source_type,
 * context, original/normalized text, hash). For each enabled non-default
 * language this inserts a `discovered` row, idempotently (the repository uses
 * INSERT IGNORE on the structural unique key, so re-discovery is a no-op).
 *
 * Status and provider are not the caller's concern: discovery always means
 * status = discovered, provider = null. The repository encodes that; this
 * service guarantees the "all target languages" fan-out.
 */
class DiscoverSourceStrings
{
    public function __construct(
        private TranslationMemoryRepository $repository,
        private ResolveRequestLanguage $languages
    ) {
    }

    /**
     * Discover the given identities into every enabled non-default language.
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
            // The repository inserts per language internally (status =
            // discovered, provider = null) and ignores rows that already exist.
            $inserted += $this->repository->insertMissingStrings(
                $identities,
                $sourceLang,
                $targetLang
            );
        }

        return $inserted;
    }

    /**
     * Discover into a single explicit target language. Useful for readers or
     * tools that want to scope discovery (e.g. re-scan just one language).
     *
     * @param TranslationSourceIdentity[] $identities
     */
    public function discoverForLanguage(array $identities, string $targetLang): int
    {
        if ($identities === [] || $targetLang === '') {
            return 0;
        }

        $sourceLang = $this->languages->getDefaultLanguage();

        if ($targetLang === $sourceLang) {
            return 0; // never create rows for the source language itself
        }

        return $this->repository->insertMissingStrings($identities, $sourceLang, $targetLang);
    }

    /**
     * Enabled languages minus the default (the source language is never a
     * translation target of itself).
     *
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

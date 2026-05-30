<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\PageTranslation\Domain\ClassifiedTranslationCandidate;
use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationStatus;

/**
 * Coordinates discover-and-resolve for one page render, by FULL IDENTITY.
 *
 * Candidates now carry their own (sourceType, sourceId, context), so lookups
 * and inserts are grouped by the identity triple — never by hash alone. The
 * same text under two identities never collides.
 *
 * - discoverForAllLanguages(): records every candidate as `discovered` for all
 *   enabled non-default languages (the "discover once → rows for fr/es/ar" rule).
 * - resolve(): returns translations for one target language, keyed by the
 *   candidate identity key so the renderer maps them back unambiguously.
 */
class ResolveTranslations
{
    public function __construct(
        private TranslationMemoryRepository $repository,
        private ResolveRequestLanguage $languages,
        private DiscoverSourceStrings $discovery
    ) {
    }

    /**
     * Discover candidates into EVERY enabled non-default language at once.
     *
     * The "all target languages" fan-out is owned by DiscoverSourceStrings — we
     * convert candidates to identities and hand them over. Insertion is
     * idempotent (INSERT IGNORE), so this is safe to call on every render.
     *
     * @param ClassifiedTranslationCandidate[] $candidates Keyed by identity key.
     */
    public function discoverForAllLanguages(array $candidates): void
    {
        if ($candidates === []) {
            return;
        }

        $this->discovery->discover($this->candidatesToIdentities($candidates));
    }

    /**
     * Convert classified candidates into source identities for discovery.
     *
     * @param ClassifiedTranslationCandidate[] $candidates
     * @return TranslationSourceIdentity[]
     */
    private function candidatesToIdentities(array $candidates): array
    {
        $identities = [];

        foreach ($candidates as $candidate) {
            $identities[] = TranslationSourceIdentity::source(
                $candidate->sourceId,
                $candidate->sourceType,
                $candidate->context,
                $candidate->originalText,
                $candidate->originalHash,
                $candidate->normalizedText
            );
        }

        return $identities;
    }

    /**
     * Resolve translations for one target language.
     *
     * @param ClassifiedTranslationCandidate[] $candidates Keyed by identity key.
     * @return array<string, string> identityKey => translated_text (translated rows only)
     */
    public function resolve(
        array $candidates,
        string $sourceLang,
        string $targetLang
    ): array {
        if ($candidates === []) {
            return [];
        }

        $translations = [];

        foreach ($this->groupByIdentity($candidates) as $group) {
            $first      = $group[array_key_first($group)];
            $sourceId   = $first->sourceId;
            $sourceType = $first->sourceType;
            $context    = $first->context;

            // hash => candidate, for this identity group.
            $byHash = [];
            foreach ($group as $candidate) {
                $byHash[$candidate->originalHash] = $candidate;
            }

            $existing = $this->repository->findManyByHashes(
                array_keys($byHash),
                $sourceLang,
                $targetLang,
                $sourceId,
                $sourceType,
                $context
            );

            $missing = $this->buildMissingIdentities($group, $existing);
            if ($missing !== []) {
                $this->repository->insertMissingStrings($missing, $sourceLang, $targetLang);
            }

            if ($existing !== []) {
                $this->repository->touchLastSeen(
                    array_keys($existing),
                    $sourceLang,
                    $targetLang,
                    $sourceId,
                    $sourceType,
                    $context
                );
            }

            // Map translated rows back onto the candidate identity key.
            foreach ($this->collectTranslations($existing) as $hash => $translated) {
                if (isset($byHash[$hash])) {
                    $translations[$byHash[$hash]->identityKey()] = $translated;
                }
            }
        }

        return $translations;
    }

    /**
     * Group candidates by their identity triple (sourceType, sourceId, context).
     *
     * @param ClassifiedTranslationCandidate[] $candidates
     * @return array<string, ClassifiedTranslationCandidate[]>
     */
    private function groupByIdentity(array $candidates): array
    {
        $grouped = [];

        foreach ($candidates as $candidate) {
            $groupKey = $candidate->sourceType . '|' . $candidate->sourceId . '|' . $candidate->context;
            $grouped[$groupKey][] = $candidate;
        }

        return $grouped;
    }

    /**
     * Build identities for candidates not already present in the DB.
     *
     * @param ClassifiedTranslationCandidate[]    $group
     * @param array<string, array<string, mixed>> $existing  keyed by hash
     * @return TranslationSourceIdentity[]
     */
    private function buildMissingIdentities(array $group, array $existing): array
    {
        $identities = [];

        foreach ($group as $candidate) {
            if (isset($existing[$candidate->originalHash])) {
                continue;
            }

            $identities[] = TranslationSourceIdentity::source(
                $candidate->sourceId,
                $candidate->sourceType,
                $candidate->context,
                $candidate->originalText,
                $candidate->originalHash,
                $candidate->normalizedText
            );
        }

        return $identities;
    }

    /**
     * @param array<string, array<string, mixed>> $existing keyed by hash
     * @return array<string, string> hash => translated_text
     */
    private function collectTranslations(array $existing): array
    {
        $out = [];

        foreach ($existing as $hash => $row) {
            $translated = (string) ($row['translated_text'] ?? '');
            $status     = (int) ($row['status'] ?? 0);

            if ($translated !== '' && $status !== TranslationStatus::DISCOVERED && $status !== TranslationStatus::IGNORED) {
                $out[$hash] = $translated;
            }
        }

        return $out;
    }
}

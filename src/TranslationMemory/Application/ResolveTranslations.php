<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\PageTranslation\Domain\TranslationCandidate;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationStatus;

/**
 * Coordinates the discover-and-resolve flow for one page render.
 *
 * Given the extractor's candidates, it performs the Step 15 sequence in bulk:
 *
 *   1. (strings are already normalized + hashed by the extractor)
 *   2. collect hashes
 *   3. ONE bulk SELECT for existing rows
 *   4. ONE bulk INSERT IGNORE for hashes not found (status = discovered)
 *   5. return a hash => translated_text map for the renderer
 *
 * Only rows that actually have a translation are returned, so the renderer
 * leaves untranslated (newly discovered) strings in the source language.
 */
class ResolveTranslations
{
    public function __construct(private TranslationMemoryRepository $repository)
    {
    }

    /**
     * @param TranslationCandidate[] $candidates Keyed by hash (as the extractor returns).
     * @return array<string, string> hash => translated_text (only translated rows)
     */
    public function resolve(
        array $candidates,
        string $sourceLang,
        string $targetLang,
        int $sourceId,
        string $sourceType
    ): array
    {
        if ($candidates === []) {
            return [];
        }

        $translations = [];

        foreach ($this->groupByContext($candidates) as $context => $contextCandidates) {
            $hashes = array_keys($contextCandidates);

            // 3. Bulk lookup of everything already known for this source identity.
            $existing = $this->repository->findManyByHashes(
                $hashes,
                $sourceLang,
                $targetLang,
                $sourceId,
                $sourceType,
                $context
            );

            // 4. Insert the ones we have never seen before, as discovered.
            $missing = $this->buildMissingIdentities($contextCandidates, $existing, $sourceId, $sourceType, $context);

            if ($missing !== []) {
                $this->repository->insertMissingStrings($missing, $sourceLang, $targetLang);
            }

            // Best-effort freshness marker for strings we did already have.
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

            $translations = array_replace($translations, $this->collectTranslations($existing));
        }

        return $translations;
    }

    /**
     * @param TranslationCandidate[] $candidates
     * @return array<string, array<string, TranslationCandidate>>
     */
    private function groupByContext(array $candidates): array
    {
        $grouped = [];

        foreach ($candidates as $hash => $candidate) {
            $grouped[$candidate->context][$hash] = $candidate;
        }

        return $grouped;
    }

    /**
     * @param TranslationCandidate[]                   $candidates
     * @param array<string, array<string, mixed>>      $existing
     * @return TranslationSourceIdentity[]
     */
    private function buildMissingIdentities(
        array $candidates,
        array $existing,
        int $sourceId,
        string $sourceType,
        string $context
    ): array
    {
        $identities = [];

        foreach ($candidates as $hash => $candidate) {
            if (isset($existing[$hash])) {
                continue;
            }

            $identities[] = TranslationSourceIdentity::source(
                $sourceId,
                $sourceType,
                $context,
                $candidate->originalText,
                $candidate->hash,
                $candidate->normalizedText
            );
        }

        return $identities;
    }

    /**
     * @param array<string, array<string, mixed>> $existing
     * @return array<string, string>
     */
    private function collectTranslations(array $existing): array
    {
        $out = [];

        foreach ($existing as $hash => $row) {
            $translated = (string) ($row['translated_text'] ?? '');
            $status     = (int) ($row['status'] ?? 0);

            // Show translation only when it exists and the status permits it.
            // Discovered and ignored keep the original; others render.
            if ($translated !== '' && $status !== TranslationStatus::DISCOVERED && $status !== TranslationStatus::IGNORED) {
                $out[$hash] = $translated;
            }
        }

        return $out;
    }
}

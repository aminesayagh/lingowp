<?php

namespace TransLangs\Frontend;

use TransLangs\Core\TranslationRepository;

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
class TranslationLookup
{
    public function __construct(private TranslationRepository $repository)
    {
    }

    /**
     * @param Candidate[] $candidates Keyed by hash (as the extractor returns).
     * @return array<string, string> hash => translated_text (only translated rows)
     */
    public function resolve(array $candidates, string $sourceLang, string $targetLang, ?string $sourceUrl = null): array
    {
        if ($candidates === []) {
            return [];
        }

        $hashes = array_keys($candidates);

        // 3. Bulk lookup of everything already known.
        $existing = $this->repository->findManyByHashes($hashes, $sourceLang, $targetLang);

        // 4. Insert the ones we have never seen before, as discovered.
        $missing = $this->buildMissingRows($candidates, $existing, $sourceUrl);

        if ($missing !== []) {
            $this->repository->insertMissingStrings($missing, $sourceLang, $targetLang);
        }

        // Best-effort freshness marker for strings we did already have.
        if ($existing !== []) {
            $this->repository->touchLastSeen(array_keys($existing), $sourceLang, $targetLang);
        }

        // 5. Return only rows that carry a usable translation.
        return $this->collectTranslations($existing);
    }

    /**
     * Rows for hashes present in candidates but absent from the DB.
     *
     * @param Candidate[]                              $candidates
     * @param array<string, array<string, mixed>>      $existing
     * @return array<int, array<string, mixed>>
     */
    private function buildMissingRows(array $candidates, array $existing, ?string $sourceUrl): array
    {
        $rows = [];

        foreach ($candidates as $hash => $candidate) {
            if (isset($existing[$hash])) {
                continue;
            }

            $rows[] = [
                'hash'            => $candidate->hash,
                'original_text'   => $candidate->originalText,
                'normalized_text' => $candidate->normalizedText,
                'context'         => $candidate->context,
                'source_url'      => $sourceUrl,
            ];
        }

        return $rows;
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
            // Discovered (0) and ignored (3) keep the original; others render.
            if ($translated !== '' && $status !== 0 && $status !== 3) {
                $out[$hash] = $translated;
            }
        }

        return $out;
    }
}
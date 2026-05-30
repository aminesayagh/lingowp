<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\Shared\Infrastructure\CacheManager;
use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;

/**
 * Orchestrates the core translation flow.
 *
 * Receives raw strings, normalizes them, hashes them, looks them up in the
 * repository (via cache), and returns translations. Reusable by the frontend
 * renderer, the admin table, and the REST editor.
 */
class TranslateStrings
{
    public function __construct(
        private TranslationMemoryRepository $repository,
        private StringNormalizer $normalizer,
        private StringHashGenerator $hasher,
        private CacheManager $cache
    ) {
    }

    /**
     * Translate a batch of raw strings.
     *
     * @param string[] $strings
     * @return array<string, string> Map of original string => translated string.
     */
    public function translateBatch(
        array $strings,
        string $sourceLang,
        string $targetLang,
        int $sourceId,
        string $sourceType,
        string $context
    ): array
    {
        $hashes = [];

        foreach ($strings as $string) {
            $normalized        = $this->normalizer->normalize($string);
            $hashes[$normalized] = $this->hasher->generate($normalized);
        }

        $found = $this->repository->findManyByHashes(
            array_values($hashes),
            $sourceLang,
            $targetLang,
            $sourceId,
            $sourceType,
            $context
        );

        $output = [];

        foreach ($hashes as $normalized => $hash) {
            if (!empty($found[$hash]['translated_text'])) {
                $output[$normalized] = $found[$hash]['translated_text'];
            }
        }

        return $output;
    }
}

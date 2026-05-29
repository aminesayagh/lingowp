<?php

namespace TransLangs\Core;

/**
 * Orchestrates the core translation flow.
 *
 * Receives raw strings, normalizes them, hashes them, looks them up in the
 * repository (via cache), and returns translations. Reusable by the frontend
 * renderer, the admin table, and the REST editor.
 */
class Translator
{
    public function __construct(
        private TranslationRepository $repository,
        private Normalizer $normalizer,
        private HashGenerator $hasher,
        private CacheManager $cache
    ) {
    }

    /**
     * Translate a batch of raw strings.
     *
     * @param string[] $strings
     * @return array<string, string> Map of original string => translated string.
     */
    public function translateBatch(array $strings, string $sourceLang, string $targetLang): array
    {
        $hashes = [];

        foreach ($strings as $string) {
            $normalized        = $this->normalizer->normalize($string);
            $hashes[$normalized] = $this->hasher->generate($normalized);
        }

        $found = $this->repository->findManyByHashes(
            array_values($hashes),
            $sourceLang,
            $targetLang
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

<?php

namespace TransLangs\TranslationMemory\Domain;

interface TranslationMemoryRepository
{
    /**
     * @param string[] $hashes
     * @return array<string, array<string, mixed>>
     */
    public function findManyByHashes(
        array $hashes,
        string $sourceLang,
        string $targetLang,
        int $sourceId,
        string $sourceType,
        string $context
    ): array;

    /**
     * @param TranslationSourceIdentity[] $identities
     */
    public function insertMissingStrings(array $identities, string $sourceLang, string $targetLang): int;

    /**
     * Find all known structured source strings for the given hashes.
     *
     * Returns distinct (source_id, source_type, context) identity tuples for
     * every hash in the list. source_id > 0 only — template text (source_id = 0)
     * is never returned because template strings are discovered by the classifier
     * itself, not matched from prior structured discovery.
     *
     * Used by ClassifyRenderedSegments to assign proper source identities to
     * rendered HTML segments before discovery/resolve.
     *
     * @param  string[] $hashes
     * @return array<string, list<array{source_id: int, source_type: string, context: string}>>
     *         Keyed by original_hash; each value is a list of matching identities.
     */
    public function findStructuredIdentitiesByHashes(array $hashes, string $sourceLang): array;

    /**
     * @param string[] $hashes
     */
    public function touchLastSeen(
        array $hashes,
        string $sourceLang,
        string $targetLang,
        int $sourceId,
        string $sourceType,
        string $context
    ): void;

    public function saveTranslation(int $id, string $translatedText, int $status): void;

    public function markDiscovered(int $id): void;

    public function markReviewed(int $id): void;

    public function markNeedsReview(int $id): void;

    public function ignoreString(int $id): void;

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array;

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function query(array $filters, int $page, int $perPage): array;

    /**
     * @return array<int, array{source_lang: string, target_lang: string}>
     */
    public function languagePairs(): array;
}

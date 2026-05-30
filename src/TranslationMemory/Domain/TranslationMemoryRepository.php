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

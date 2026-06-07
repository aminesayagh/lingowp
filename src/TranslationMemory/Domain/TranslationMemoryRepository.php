<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * Contract for the translation persistence layer.
 *
 * Covers row insertion, admin mutations, admin listing, and translation
 * preloads for the pipeline that will be rebuilt next.
 *
 * source_lang is never stored or passed — it is read from get_locale() at runtime.
 */
interface TranslationMemoryRepository
{
    /**
     * Insert normalized source identities not yet present for $targetLang.
     * Duplicates (same unique key) are silently skipped via INSERT IGNORE.
     *
     * @param TranslationSourceIdentity[] $identities
     * @return int Rows actually inserted.
     */
    public function insertMissingStrings(array $identities, string $targetLang): int;

    /**
     * @param TranslationSourceIdentity[] $identities
     */
    public function recordI18nObservations(
        array $identities,
        string $targetLang,
        string $sourceUrl,
        string $requestArea
    ): void;

    /**
     * @param string[] $currentHashesRaw Raw SHA-256 bytes for current post-field strings.
     */
    public function deleteStalePostStrings(int $postId, array $currentHashesRaw): void;

    /**
     * @param string[] $currentHashesRaw Raw SHA-256 bytes for current postmeta strings.
     */
    public function deleteStalePostmetaStrings(int $postId, array $currentHashesRaw): void;

    // --- Admin mutations ---

    public function saveTranslation(RowRef $rowRef, string $text, int $status): void;

    public function markDiscovered(RowRef $rowRef): void;

    public function markReviewed(RowRef $rowRef): void;

    public function markNeedsReview(RowRef $rowRef): void;

    public function ignoreString(RowRef $rowRef): void;

    // --- Admin lookup ---

    /** @return array<string, mixed>|null */
    public function find(RowRef $rowRef): ?array;

    /**
     * @param array{
     *   target_lang?: string, status?: int|null, statuses?: int[],
     *   search_original?: string, search_translated?: string,
     *   route_table?: string
     * } $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function query(array $filters, int $page, int $perPage): array;

    // --- Render preload (hot path — one query per entity, never per string) ---

    /**
     * @return array<string, string> "field:<field>:<hex>" => translated_text
     */
    public function getPostTranslations(string $targetLang, int $postId): array;

    /**
     * @return array<string, string> "meta:<hex>:<hex>:<hex>" => translated_text
     */
    public function getPostmetaTranslations(string $targetLang, int $postId): array;

    /**
     * @param int[] $termTaxonomyIds
     * @return array<string, string> "term:<ttId>:<field>:<hex>" => translated_text
     */
    public function getTermTranslations(string $targetLang, array $termTaxonomyIds): array;

    /**
     * @return array<string, string> "i18n:<source>:<hex>:<hex>" => translated_text
     */
    public function getI18nTranslations(string $targetLang, string $source): array;
}

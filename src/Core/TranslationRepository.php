<?php

namespace TransLangs\Core;

use TransLangs\Setup\Schema;

/**
 * Database access layer for the translation memory.
 *
 * Hard rule: always operate in bulk. Never run one query per string.
 * Methods are stubbed for the skeleton; fill them out in Phase 2 / Phase 6.
 */
class TranslationRepository
{
    /**
     * Look up many translations at once by hash.
     *
     * @param string[] $hashes
     * @return array<string, array<string, mixed>> Keyed by original_hash.
     */
    public function findManyByHashes(array $hashes, string $sourceLang, string $targetLang): array
    {
        if (empty($hashes)) {
            return [];
        }

        global $wpdb;
        $table = Schema::stringsTable();

        $placeholders = implode(',', array_fill(0, count($hashes), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders are built safely above.
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE source_lang = %s AND target_lang = %s
             AND original_hash IN ({$placeholders})",
            array_merge([$sourceLang, $targetLang], $hashes)
        );

        $rows   = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $result = [];

        foreach ($rows as $row) {
            $result[$row['original_hash']] = $row;
        }

        return $result;
    }

    /**
     * Insert strings that were discovered on a page but not yet stored.
     *
     * @param array<int, array<string, mixed>> $candidates
     */
    public function insertMissingStrings(array $candidates, string $sourceLang, string $targetLang): void
    {
        // Phase 6: build a single multi-row INSERT IGNORE.
    }

    public function saveTranslation(int $id, string $translatedText, int $status): void
    {
        // Phase 7: update translated_text, status, updated_at.
    }

    public function markReviewed(int $id): void
    {
        // Phase 7.
    }

    public function ignoreString(int $id): void
    {
        // Phase 7.
    }
}

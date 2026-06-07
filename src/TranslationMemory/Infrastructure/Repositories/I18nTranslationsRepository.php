<?php

namespace TransLangs\TranslationMemory\Infrastructure\Repositories;

use TransLangs\TranslationMemory\Domain\SourceRoute;
use TransLangs\TranslationMemory\Domain\TranslationMapKey;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;

final class I18nTranslationsRepository
{
    private const CHUNK = 100;

    public function __construct(private \wpdb $wpdb) {}

    /**
     * @param TranslationSourceIdentity[] $identities  Only SourceRoute::I18n entries.
     * @return int Rows actually inserted.
     */
    public function insertMissing(array $identities, string $targetLang): int
    {
        $filtered = array_values(array_filter(
            $identities,
            fn(TranslationSourceIdentity $i): bool => $i->routeTable === SourceRoute::I18n
        ));

        if ($filtered === []) {
            return 0;
        }

        $table = $this->table();
        $now   = current_time('mysql');
        $count = 0;

        foreach (array_chunk($filtered, self::CHUNK) as $batch) {
            $placeholders = [];
            $values       = [];

            foreach ($batch as $identity) {
                $placeholders[] = '(%s, %s, %s, UNHEX(%s), UNHEX(%s), %s, 0, %s, %s)';
                array_push(
                    $values,
                    $targetLang,
                    $identity->source,
                    $identity->reference ?? '',
                    bin2hex((string) $identity->referenceHash),
                    bin2hex($identity->originalHash),
                    $identity->originalText,
                    $now,
                    $now
                );
            }

            $sql   = 'INSERT IGNORE INTO ' . $table
                   . ' (target_lang, source, reference, reference_hash, original_hash, original_text, status, created_at, updated_at)'
                   . ' VALUES ' . implode(', ', $placeholders);
            $count += (int) $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $this->wpdb->prepare($sql, ...$values) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            );
        }

        return $count;
    }

    /**
     * @param TranslationSourceIdentity[] $identities
     */
    public function recordObservations(
        array $identities,
        string $targetLang,
        string $sourceUrl,
        string $requestArea
    ): void {
        $filtered = array_values(array_filter(
            $identities,
            fn(TranslationSourceIdentity $i): bool => $i->routeTable === SourceRoute::I18n
        ));

        if ($filtered === []) {
            return;
        }

        $now = current_time('mysql');

        foreach (array_chunk($filtered, self::CHUNK) as $batch) {
            $rowIds = $this->idsByIdentity($batch, $targetLang);
            if ($rowIds === []) {
                continue;
            }

            $placeholders = [];
            $values       = [];

            foreach ($batch as $identity) {
                $key = $this->identityKey($identity, $targetLang);
                if (!isset($rowIds[$key])) {
                    continue;
                }

                if ($identity->source === 'rendered_html') {
                    $placeholders[] = '(%d, %s, %s, %s, 1, %s, %s)';
                    array_push(
                        $values,
                        $rowIds[$key],
                        $sourceUrl,
                        (string) $identity->reference,
                        $requestArea,
                        $now,
                        $now
                    );
                    continue;
                }

                $placeholders[] = '(%d, %s, NULL, %s, 1, %s, %s)';
                array_push(
                    $values,
                    $rowIds[$key],
                    $sourceUrl,
                    $requestArea,
                    $now,
                    $now
                );
            }

            if ($placeholders === []) {
                continue;
            }

            $sql = 'INSERT INTO ' . $this->metaTable()
                 . ' (i18n_id, source_url, selector, request_area, seen_count, first_seen_at, last_seen_at)'
                 . ' VALUES ' . implode(', ', $placeholders)
                 . ' ON DUPLICATE KEY UPDATE'
                 . ' source_url = VALUES(source_url),'
                 . ' selector = VALUES(selector),'
                 . ' request_area = VALUES(request_area),'
                 . ' seen_count = seen_count + 1,'
                 . ' last_seen_at = VALUES(last_seen_at)';

            $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $this->wpdb->prepare($sql, ...$values) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            );
        }
    }

    public function save(int $id, string $text, int $status): void
    {
        $this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $this->table(),
            ['translated_text' => $text, 'status' => $status, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }

    public function updateStatus(int $id, int $status): void
    {
        $this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $this->table(),
            ['status' => $status, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );
    }

    /**
     * Delete i18n strings by original text pattern.
     * Useful for cleaning up admin bar strings or other unwanted captures.
     *
     * @param string[] $originalTexts
     * @return int Number of rows deleted.
     */
    public function deleteByOriginalTexts(array $originalTexts): int
    {
        if ($originalTexts === []) {
            return 0;
        }

        $placeholders = array_fill(0, count($originalTexts), '%s');
        $sql = 'DELETE FROM ' . $this->table() . ' WHERE original_text IN (' . implode(',', $placeholders) . ')';

        return (int) $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $this->wpdb->prepare($sql, ...$originalTexts) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $table = $this->table();
        $row   = $this->wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT id, target_lang, source, reference,
                        HEX(reference_hash) AS reference_hash,
                        HEX(original_hash) AS original_hash,
                        original_text, translated_text, status, provider,
                        created_at, updated_at
                 FROM {$table} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Preload all renderable i18n translations for a source (plugin text domain).
     * Renderable statuses: machine(1), reviewed(2), needs_review(4).
     *
     * @return array<string, string> MapKey => translated_text
     */
    public function getTranslations(string $targetLang, string $source): array
    {
        $table = $this->table();
        $rows  = (array) $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT source, reference_hash, original_hash, translated_text
                 FROM {$table}
                 WHERE target_lang = %s AND source = %s
                   AND status IN (1, 2, 4)",
                $targetLang,
                $source
            ),
            ARRAY_A
        );

        $map = [];
        foreach ($rows as $row) {
            $map[TranslationMapKey::forI18n(
                (string) $row['source'],
                (string) $row['reference_hash'],
                (string) $row['original_hash']
            )] = (string) $row['translated_text'];
        }

        return $map;
    }

    private function table(): string
    {
        return $this->wpdb->prefix . 'tl_translations_i18n';
    }

    private function metaTable(): string
    {
        return $this->wpdb->prefix . 'tl_translations_i18n_meta';
    }

    /**
     * @param TranslationSourceIdentity[] $identities
     * @return array<string, int>
     */
    private function idsByIdentity(array $identities, string $targetLang): array
    {
        $clauses = [];
        $values  = [$targetLang];

        foreach ($identities as $identity) {
            $clauses[] = '(source = %s AND reference_hash = UNHEX(%s) AND original_hash = UNHEX(%s))';
            array_push(
                $values,
                $identity->source,
                bin2hex((string) $identity->referenceHash),
                bin2hex($identity->originalHash)
            );
        }

        if ($clauses === []) {
            return [];
        }

        $rows = (array) $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "SELECT id, source, HEX(reference_hash) AS reference_hash, HEX(original_hash) AS original_hash
                 FROM {$this->table()}
                 WHERE target_lang = %s
                   AND (" . implode(' OR ', $clauses) . ')',
                ...$values
            ),
            ARRAY_A
        );

        $ids = [];

        foreach ($rows as $row) {
            $ids[implode(':', [
                $targetLang,
                (string) $row['source'],
                strtolower((string) $row['reference_hash']),
                strtolower((string) $row['original_hash']),
            ])] = (int) $row['id'];
        }

        return $ids;
    }

    private function identityKey(TranslationSourceIdentity $identity, string $targetLang): string
    {
        return implode(':', [
            $targetLang,
            (string) $identity->source,
            bin2hex((string) $identity->referenceHash),
            bin2hex($identity->originalHash),
        ]);
    }
}

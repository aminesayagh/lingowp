<?php

namespace TransLangs\TranslationMemory\Infrastructure\Repositories;

use TransLangs\ContentSource\Domain\SourceRoute;
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
}

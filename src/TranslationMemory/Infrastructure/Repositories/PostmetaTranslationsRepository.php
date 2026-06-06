<?php

namespace TransLangs\TranslationMemory\Infrastructure\Repositories;

use TransLangs\ContentSource\Domain\SourceRoute;
use TransLangs\TranslationMemory\Domain\TranslationMapKey;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;

final class PostmetaTranslationsRepository
{
    private const CHUNK = 100;

    public function __construct(private \wpdb $wpdb) {}

    /**
     * @param TranslationSourceIdentity[] $identities  Only SourceRoute::Postmeta entries.
     * @return int Rows actually inserted.
     */
    public function insertMissing(array $identities, string $targetLang): int
    {
        $filtered = array_values(array_filter(
            $identities,
            fn(TranslationSourceIdentity $i): bool => $i->routeTable === SourceRoute::Postmeta
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
                // context is nullable — use NULL literal in SQL to avoid empty-string collision.
                if ($identity->context === null) {
                    $placeholders[] = '(%d, %s, %s, UNHEX(%s), NULL, UNHEX(%s), UNHEX(%s), %s, 0, %s, %s)';
                    array_push(
                        $values,
                        $identity->parentId,
                        $targetLang,
                        $identity->metaKey,
                        bin2hex((string) $identity->metaKeyHash),
                        bin2hex((string) $identity->contextHash),
                        bin2hex($identity->originalHash),
                        $identity->originalText,
                        $now,
                        $now
                    );
                } else {
                    $placeholders[] = '(%d, %s, %s, UNHEX(%s), %s, UNHEX(%s), UNHEX(%s), %s, 0, %s, %s)';
                    array_push(
                        $values,
                        $identity->parentId,
                        $targetLang,
                        $identity->metaKey,
                        bin2hex((string) $identity->metaKeyHash),
                        $identity->context,
                        bin2hex((string) $identity->contextHash),
                        bin2hex($identity->originalHash),
                        $identity->originalText,
                        $now,
                        $now
                    );
                }
            }

            $sql   = 'INSERT IGNORE INTO ' . $table
                   . ' (post_id, target_lang, meta_key, meta_key_hash, context, context_hash,'
                   . '  original_hash, original_text, status, created_at, updated_at)'
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
                "SELECT id, post_id, target_lang, meta_key,
                        HEX(meta_key_hash) AS meta_key_hash,
                        context, HEX(context_hash) AS context_hash,
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
     * Preload all renderable meta translations for a single post.
     * Renderable statuses: machine(1), reviewed(2), needs_review(4).
     *
     * @return array<string, string> MapKey => translated_text
     */
    public function getTranslations(string $targetLang, int $postId): array
    {
        $table = $this->table();
        $rows  = (array) $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT meta_key_hash, context_hash, original_hash, translated_text
                 FROM {$table}
                 WHERE target_lang = %s AND post_id = %d
                   AND status IN (1, 2, 4)",
                $targetLang,
                $postId
            ),
            ARRAY_A
        );

        $map = [];
        foreach ($rows as $row) {
            $map[TranslationMapKey::forPostmeta(
                (string) $row['meta_key_hash'],
                (string) $row['context_hash'],
                (string) $row['original_hash']
            )] = (string) $row['translated_text'];
        }

        return $map;
    }

    private function table(): string
    {
        return $this->wpdb->prefix . 'tl_translations_postmeta';
    }
}

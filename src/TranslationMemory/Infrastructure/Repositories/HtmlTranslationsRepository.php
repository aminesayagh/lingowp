<?php

namespace TransLangs\TranslationMemory\Infrastructure\Repositories;

use TransLangs\TranslationMemory\Domain\SourceRoute;
use TransLangs\TranslationMemory\Domain\TranslationMapKey;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;

/**
 * Rendered-HTML fallback strings — chrome/theme text the structural readers
 * never own (menus, widgets, hard-coded template strings).
 *
 * Identity is the structural selector ("header>nav>a") plus the normalized
 * original hash, scoped to a target language. Unlike _i18n there is no `source`
 * discriminator and no separate meta table: `source_url` is a plain column
 * holding the last URL the string was observed on.
 */
final class HtmlTranslationsRepository
{
    private const CHUNK = 100;

    public function __construct(private \wpdb $wpdb) {}

    /**
     * @param TranslationSourceIdentity[] $identities  Only SourceRoute::Html entries.
     * @return int Rows actually inserted.
     */
    public function insertMissing(array $identities, string $targetLang): int
    {
        $filtered = $this->htmlIdentities($identities);

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
                $placeholders[] = '(%s, %s, UNHEX(%s), %s, 0, %s, %s)';
                array_push(
                    $values,
                    $targetLang,
                    (string) $identity->reference,
                    bin2hex($identity->originalHash),
                    $identity->originalText,
                    $now,
                    $now
                );
            }

            $sql   = 'INSERT IGNORE INTO ' . $table
                   . ' (target_lang, selector, original_hash, original_text, status, created_at, updated_at)'
                   . ' VALUES ' . implode(', ', $placeholders);
            $count += (int) $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $this->wpdb->prepare($sql, ...$values) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            );
        }

        return $count;
    }

    /**
     * Record where each string was last seen. Only `source_url` is touched —
     * `updated_at` is intentionally left alone so render traffic never reorders
     * the admin list (which sorts by updated_at).
     *
     * @param TranslationSourceIdentity[] $identities
     */
    public function recordObservations(array $identities, string $targetLang, string $sourceUrl): void
    {
        $filtered = $this->htmlIdentities($identities);

        if ($filtered === [] || $sourceUrl === '') {
            return;
        }

        $table = $this->table();

        foreach (array_chunk($filtered, self::CHUNK) as $batch) {
            $clauses = [];
            $values  = [$sourceUrl, $targetLang];

            foreach ($batch as $identity) {
                $clauses[] = '(selector = %s AND original_hash = UNHEX(%s))';
                array_push(
                    $values,
                    (string) $identity->reference,
                    bin2hex($identity->originalHash)
                );
            }

            $sql = 'UPDATE ' . $table . ' SET source_url = %s'
                 . ' WHERE target_lang = %s AND (' . implode(' OR ', $clauses) . ')';

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

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $table = $this->table();
        $row   = $this->wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT id, target_lang, selector,
                        HEX(original_hash) AS original_hash,
                        original_text, translated_text, status, provider,
                        source_url, created_at, updated_at
                 FROM {$table} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Preload all renderable rendered-HTML translations for a language.
     * Renderable statuses: machine(1), reviewed(2), needs_review(4).
     *
     * @return array<string, string> MapKey => translated_text
     */
    public function getTranslations(string $targetLang): array
    {
        $table = $this->table();
        $rows  = (array) $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT selector, original_hash, translated_text
                 FROM {$table}
                 WHERE target_lang = %s
                   AND status IN (1, 2, 4)",
                $targetLang
            ),
            ARRAY_A
        );

        $map = [];
        foreach ($rows as $row) {
            $map[TranslationMapKey::forHtml(
                (string) $row['selector'],
                (string) $row['original_hash']
            )] = (string) $row['translated_text'];
        }

        return $map;
    }

    private function table(): string
    {
        return $this->wpdb->prefix . 'tl_translations_html';
    }

    /**
     * Keep only Html-routed identities that carry a non-empty selector; an empty
     * selector cannot identify a rendered string and must never be stored.
     *
     * @param TranslationSourceIdentity[] $identities
     * @return TranslationSourceIdentity[]
     */
    private function htmlIdentities(array $identities): array
    {
        return array_values(array_filter(
            $identities,
            static fn(TranslationSourceIdentity $i): bool =>
                $i->routeTable === SourceRoute::Html
                && (string) $i->reference !== ''
        ));
    }
}

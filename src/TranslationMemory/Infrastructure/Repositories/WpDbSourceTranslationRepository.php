<?php

namespace TransLangs\TranslationMemory\Infrastructure\Repositories;

use TransLangs\TranslationMemory\Domain\SourceRecord;
use TransLangs\TranslationMemory\Domain\SourceTranslationRepository;
use TransLangs\TranslationMemory\Infrastructure\TranslationMemorySchema;

/**
 * $wpdb implementation of the v4 persistence contract (architecture §2).
 *
 * This is the ONE class that writes wp_tl_source and the only code aware of its
 * column names — it consumes typed SourceRecord objects through SourceRecord::row().
 * Source keys are binary(32); they travel through SQL as hex via UNHEX(%s) so a
 * single $wpdb->prepare() handles them safely alongside the text columns. Insert
 * statements are chunked to bound packet size.
 */
final class WpDbSourceTranslationRepository implements SourceTranslationRepository
{
    /** Max source rows per INSERT statement (bounded packet size). */
    private const INSERT_CHUNK = 100;

    /** Max keys per render SELECT IN-list (bounded packet/statement size). */
    private const SELECT_CHUNK = 200;

    public function __construct(private \wpdb $wpdb) {}

    public function upsertSources(array $records): int
    {
        if ($records === []) {
            return 0;
        }

        $table    = TranslationMemorySchema::tlSourceTable();
        $now      = current_time('mysql');
        $inserted = 0;

        foreach (array_chunk($records, self::INSERT_CHUNK) as $chunk) {
            $rows = [];
            $args = [];

            foreach ($chunk as $record) {
                $r = $record->row();

                // Format string is built in code (safe); every value is a
                // placeholder. NULL columns use a literal NULL, not a placeholder.
                $rows[] = '(UNHEX(%s), %d, '
                    . ($r['post_id'] === null ? 'NULL' : '%d') . ', '
                    . ($r['term_taxonomy_id'] === null ? 'NULL' : '%d') . ', '
                    . '%s, '
                    . ($r['ref2'] === null ? 'NULL' : '%s') . ', '
                    . ($r['source_url'] === null ? 'NULL' : '%s') . ', '
                    . 'UNHEX(%s), %s, 0, %s, %s)';

                $args[] = bin2hex($r['source_key']);
                $args[] = $r['type'];
                if ($r['post_id'] !== null) {
                    $args[] = $r['post_id'];
                }
                if ($r['term_taxonomy_id'] !== null) {
                    $args[] = $r['term_taxonomy_id'];
                }
                $args[] = $r['ref1'];
                if ($r['ref2'] !== null) {
                    $args[] = $r['ref2'];
                }
                if ($r['source_url'] !== null) {
                    $args[] = $r['source_url'];
                }
                $args[] = bin2hex($r['current_hash']);
                $args[] = $r['original_text'];
                $args[] = $now; // created_at
                $args[] = $now; // updated_at
            }

            $sql = "INSERT INTO {$table}
                        (source_key, type, post_id, term_taxonomy_id, ref1, ref2,
                         source_url, current_hash, original_text, status, created_at, updated_at)
                    VALUES " . implode(', ', $rows) . "
                    ON DUPLICATE KEY UPDATE
                        original_text = VALUES(original_text),
                        current_hash  = VALUES(current_hash),
                        updated_at    = VALUES(updated_at)";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $affected = $this->wpdb->query($this->wpdb->prepare($sql, ...$args));

            // The locational key is stable across edits, so a duplicate is an
            // edited string: its text + current_hash are updated in place (D27),
            // never re-inserted; the attached translations stay put.
            if (is_int($affected) && $affected > 0) {
                $inserted += $affected;
            }
        }

        return $inserted;
    }

    public function deleteStaleSourcesForPost(int $postId, array $currentKeys): void
    {
        $table = TranslationMemorySchema::tlSourceTable();

        if ($currentKeys === []) {
            $this->wpdb->query( // phpcs:ignore WordPress.DB
                $this->wpdb->prepare("DELETE FROM {$table} WHERE post_id = %d", $postId)
            );
            return;
        }

        [$placeholders, $hexes] = $this->notInList($currentKeys);

        $sql = "DELETE FROM {$table}
                WHERE post_id = %d
                  AND source_key NOT IN ({$placeholders})";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, $postId, ...$hexes));
    }

    public function deleteStaleSourcesForPostMetaKey(int $postId, string $metaKey, array $currentKeys): void
    {
        $table = TranslationMemorySchema::tlSourceTable();
        $type  = \TransLangs\TranslationMemory\Domain\TL_Key::TYPE_POSTMETA;

        if ($currentKeys === []) {
            $this->wpdb->query( // phpcs:ignore WordPress.DB
                $this->wpdb->prepare(
                    "DELETE FROM {$table} WHERE post_id = %d AND type = %d AND ref1 = %s",
                    $postId,
                    $type,
                    $metaKey
                )
            );
            return;
        }

        [$placeholders, $hexes] = $this->notInList($currentKeys);

        $sql = "DELETE FROM {$table}
                WHERE post_id = %d
                  AND type = %d
                  AND ref1 = %s
                  AND source_key NOT IN ({$placeholders})";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, $postId, $type, $metaKey, ...$hexes));
    }

    public function deleteStaleSourcesForTerm(int $termTaxonomyId, array $currentKeys): void
    {
        $table = TranslationMemorySchema::tlSourceTable();

        if ($currentKeys === []) {
            $this->wpdb->query( // phpcs:ignore WordPress.DB
                $this->wpdb->prepare("DELETE FROM {$table} WHERE term_taxonomy_id = %d", $termTaxonomyId)
            );
            return;
        }

        [$placeholders, $hexes] = $this->notInList($currentKeys);

        $sql = "DELETE FROM {$table}
                WHERE term_taxonomy_id = %d
                  AND source_key NOT IN ({$placeholders})";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, $termTaxonomyId, ...$hexes));
    }

    public function fetchTranslations(array $rawKeys, string $langCode): array
    {
        if ($rawKeys === [] || $langCode === '') {
            return [];
        }

        $table = TranslationMemorySchema::tlTranslationTable();
        $out   = [];

        // Statuses 1 (machine), 2 (reviewed), 4 (needs_review) are renderable.
        foreach (array_chunk(array_values($rawKeys), self::SELECT_CHUNK) as $chunk) {
            [$placeholders, $hexes] = $this->notInList($chunk);

            $sql = "SELECT HEX(source_key) AS k, translated_text
                    FROM {$table}
                    WHERE lang_code = %s
                      AND status IN (1, 2, 4)
                      AND source_key IN ({$placeholders})";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $langCode, ...$hexes), ARRAY_A);

            foreach ((array) $rows as $row) {
                $out[strtolower((string) $row['k'])] = (string) $row['translated_text'];
            }
        }

        return $out;
    }

    /**
     * Build the `UNHEX(%s), …` placeholder list and matching hex args for a set
     * of raw binary keys.
     *
     * @param  string[] $rawKeys
     * @return array{0: string, 1: string[]}
     */
    private function notInList(array $rawKeys): array
    {
        $hexes        = array_map('bin2hex', array_values($rawKeys));
        $placeholders = implode(', ', array_fill(0, count($hexes), 'UNHEX(%s)'));

        return [$placeholders, $hexes];
    }
}

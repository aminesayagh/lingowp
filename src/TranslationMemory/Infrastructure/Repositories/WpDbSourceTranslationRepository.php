<?php

namespace TransLangs\TranslationMemory\Infrastructure\Repositories;

use TransLangs\TranslationMemory\Domain\SourceCandidate;
use TransLangs\TranslationMemory\Domain\SourceTranslationRepository;
use TransLangs\TranslationMemory\Infrastructure\TranslationMemorySchema;

/**
 * $wpdb implementation of the v4 persistence contract (architecture §2).
 *
 * Source keys are binary(32); they travel through SQL as hex via UNHEX(%s) so a
 * single $wpdb->prepare() handles them safely alongside the text columns. Insert
 * statements are chunked to bound packet size.
 */
final class WpDbSourceTranslationRepository implements SourceTranslationRepository
{
    /** Max source rows per INSERT statement (bounded packet size). */
    private const INSERT_CHUNK = 100;

    public function __construct(private \wpdb $wpdb) {}

    public function upsertSources(array $candidates): int
    {
        if ($candidates === []) {
            return 0;
        }

        $table    = TranslationMemorySchema::tlSourceTable();
        $now      = current_time('mysql');
        $inserted = 0;

        foreach (array_chunk($candidates, self::INSERT_CHUNK) as $chunk) {
            $rows = [];
            $args = [];

            foreach ($chunk as $candidate) {
                $postId = $candidate->postId();
                $ttId   = $candidate->termTaxonomyId();
                $ref2   = $candidate->ref2;

                // Format string is built in code (safe); every value is a
                // placeholder. NULL columns use a literal NULL, not a placeholder.
                $rows[] = '(UNHEX(%s), %d, '
                    . ($postId === null ? 'NULL' : '%d') . ', '
                    . ($ttId === null ? 'NULL' : '%d') . ', '
                    . '%s, '
                    . ($ref2 === null ? 'NULL' : '%s') . ', '
                    . 'UNHEX(%s), %s, 0, %s)';

                $args[] = bin2hex($candidate->key());
                $args[] = $candidate->type;
                if ($postId !== null) {
                    $args[] = $postId;
                }
                if ($ttId !== null) {
                    $args[] = $ttId;
                }
                $args[] = $candidate->ref1;
                if ($ref2 !== null) {
                    $args[] = $ref2;
                }
                $args[] = bin2hex($candidate->originalHash());
                $args[] = $candidate->originalText;
                $args[] = $now;
            }

            $sql = "INSERT INTO {$table}
                        (source_key, type, post_id, term_taxonomy_id, ref1, ref2,
                         original_hash, original_text, status, created_at)
                    VALUES " . implode(', ', $rows) . "
                    ON DUPLICATE KEY UPDATE id = id";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $affected = $this->wpdb->query($this->wpdb->prepare($sql, ...$args));

            // INSERT … ON DUPLICATE counts 1 per real insert, 0 for the no-op
            // update on a duplicate, so affected rows ≈ newly inserted rows.
            if (is_int($affected) && $affected > 0) {
                $inserted += $affected;
            }
        }

        return $inserted;
    }

    public function deleteStaleSourcesForPost(int $postId, array $currentKeys): void
    {
        $this->deleteStaleSources('post_id', $postId, $currentKeys);
    }

    public function deleteStaleSourcesForTerm(int $termTaxonomyId, array $currentKeys): void
    {
        $this->deleteStaleSources('term_taxonomy_id', $termTaxonomyId, $currentKeys);
    }

    /**
     * Delete owner rows whose source_key is not in the current set.
     *
     * @param string[] $currentKeys Raw 32-byte keys still present.
     */
    private function deleteStaleSources(string $ownerColumn, int $ownerId, array $currentKeys): void
    {
        $table = TranslationMemorySchema::tlSourceTable();

        if ($currentKeys === []) {
            // No translatable strings left for this owner — remove them all.
            $this->wpdb->query( // phpcs:ignore WordPress.DB
                $this->wpdb->prepare("DELETE FROM {$table} WHERE {$ownerColumn} = %d", $ownerId)
            );
            return;
        }

        $hexes        = array_map('bin2hex', array_values($currentKeys));
        $placeholders = implode(', ', array_fill(0, count($hexes), 'UNHEX(%s)'));

        $sql = "DELETE FROM {$table}
                WHERE {$ownerColumn} = %d
                  AND source_key NOT IN ({$placeholders})";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $this->wpdb->query($this->wpdb->prepare($sql, $ownerId, ...$hexes));
    }
}

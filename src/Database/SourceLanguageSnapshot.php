<?php

namespace TransLangs\Database;

/**
 * Snapshots the current source content as a translation under a given
 * language code — the "save the old source as a target" half of a source
 * language transition (see SourceLanguageTransition).
 *
 * Every parent row's original_text already IS that language's content, so
 * turning it into a translation is a single INSERT ... SELECT per table, not
 * a per-row loop. INSERT IGNORE on each child's existing unique key makes
 * this idempotent — safe to call even if some rows already carry a
 * translation for that code.
 */
final class SourceLanguageSnapshot
{
    public function __construct(private \wpdb $wpdb) {}

    public function copyCurrentSourceAsTranslation(string $langCode): void
    {
        $parents = TranslationMemorySchema::parentTables();
        $now     = current_time('mysql');

        foreach ($parents as $parentKey => $parent) {
            $child = $parent . '_translation';

            if (in_array($parentKey, TranslationMemorySchema::HASH_LINK_CHILDREN, true)) {
                $this->wpdb->query($this->wpdb->prepare( // phpcs:ignore WordPress.DB
                    "INSERT IGNORE INTO {$child}
                        (original_hash, lang_code, translated_text, status, provider, created_at, updated_at)
                     SELECT original_hash, %s, original_text, 2, NULL, %s, %s
                     FROM {$parent}",
                    $langCode,
                    $now,
                    $now
                ));

                continue;
            }

            // Parent-id children (tl_meta, tl_staticText): keyed (parent_id, lang_code[, form_index]).
            if ($parentKey === 'tl_staticText') {
                $this->wpdb->query($this->wpdb->prepare( // phpcs:ignore WordPress.DB
                    "INSERT IGNORE INTO {$child}
                        (parent_id, lang_code, form_index, translated_text, status, provider, created_at, updated_at)
                     SELECT id, %s, 0, original_text, 2, NULL, %s, %s
                     FROM {$parent}",
                    $langCode,
                    $now,
                    $now
                ));

                continue;
            }

            $this->wpdb->query($this->wpdb->prepare( // phpcs:ignore WordPress.DB
                "INSERT IGNORE INTO {$child}
                    (parent_id, lang_code, translated_text, status, provider, created_at, updated_at)
                 SELECT id, %s, original_text, 2, NULL, %s, %s
                 FROM {$parent}",
                $langCode,
                $now,
                $now
            ));
        }
    }
}

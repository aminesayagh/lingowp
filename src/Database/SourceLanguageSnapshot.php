<?php

namespace LingoWP\Database;

final class SourceLanguageSnapshot
{
    private \wpdb $wpdb;

    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

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

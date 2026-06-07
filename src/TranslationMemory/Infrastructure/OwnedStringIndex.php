<?php

namespace TransLangs\TranslationMemory\Infrastructure;

final class OwnedStringIndex
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(private \wpdb $wpdb) {}

    public function existsOwnedHash(string $originalHashRaw): bool
    {
        $hashHex = bin2hex($originalHashRaw);
        if (strlen($hashHex) !== 64) {
            return false;
        }

        if (array_key_exists($hashHex, $this->cache)) {
            return $this->cache[$hashHex];
        }

        foreach ($this->ownedTables() as $table) {
            $found = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    "SELECT 1
                     FROM {$table}
                     WHERE original_hash = UNHEX(%s)
                     LIMIT 1",
                    $hashHex
                )
            );

            if ((int) $found === 1) {
                return $this->cache[$hashHex] = true;
            }
        }

        return $this->cache[$hashHex] = false;
    }

    /**
     * @return string[]
     */
    private function ownedTables(): array
    {
        return [
            $this->wpdb->prefix . 'tl_translations_post',
            $this->wpdb->prefix . 'tl_translations_postmeta',
            $this->wpdb->prefix . 'tl_translations_terms',
        ];
    }
}

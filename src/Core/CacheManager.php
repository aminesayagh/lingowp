<?php

namespace TransLangs\Core;

/**
 * Wraps WordPress object cache for translation lookups.
 *
 * Stub for the MVP: backed by the in-memory object cache. A persistent
 * cache plugin (Redis/Memcached) makes this survive across requests.
 * Full-page translated cache comes in a later phase.
 */
class CacheManager
{
    private const GROUP = 'translangs';

    public function get(string $key): mixed
    {
        $found = false;
        $value = wp_cache_get($key, self::GROUP, false, $found);

        return $found ? $value : null;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        wp_cache_set($key, $value, self::GROUP, $ttl);
    }

    public function delete(string $key): void
    {
        wp_cache_delete($key, self::GROUP);
    }

    /**
     * Build a lookup key for a language pair and hash.
     */
    public function key(string $sourceLang, string $targetLang, string $hash): string
    {
        return "translangs_{$sourceLang}_{$targetLang}_{$hash}";
    }
}

<?php

namespace LingoWP\GettextDomains;

final class GettextTranslatedPercentCache
{
    private const PREFIX = 'lingowp_gettext_percent_';
    private const TTL    = HOUR_IN_SECONDS;

    public function get(string $type, string $domain, string $locale): ?array
    {
        $cached = get_transient(self::key($type, $domain, $locale));

        return is_array($cached) ? $cached : null;
    }

    public function set(string $type, string $domain, string $locale, ?int $percent): void
    {
        set_transient(self::key($type, $domain, $locale), ['percent' => $percent], self::TTL);
    }

    public function invalidate(string $type, string $domain, string $locale): void
    {
        delete_transient(self::key($type, $domain, $locale));
    }

    private static function key(string $type, string $domain, string $locale): string
    {
        return self::PREFIX . md5("{$type}:{$domain}:{$locale}");
    }
}

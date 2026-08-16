<?php

namespace TransLangs\GettextDomains;

/**
 * Cached translated-percent snapshot for one domain+locale, so the
 * Plugins & Themes list can show a number without re-scanning a component's
 * entire source tree on every page load. The scan itself still lives in
 * GettextDomainRestController::translatedPercent() — this only stores its
 * result. Kept separate from GettextDomainRegistry, which explicitly never
 * detects or repairs its own staleness — this cache's whole job is exactly
 * that (a TTL, plus explicit invalidation from this feature's write paths).
 */
final class GettextTranslatedPercentCache
{
    private const PREFIX = 'translangs_gettext_percent_';
    private const TTL    = HOUR_IN_SECONDS;

    /** @return array{percent: ?int}|null Null = not cached (a scan is needed). */
    public function get(string $type, string $domain, string $locale): ?array
    {
        $cached = get_transient(self::key($type, $domain, $locale));

        return is_array($cached) ? $cached : null;
    }

    public function set(string $type, string $domain, string $locale, ?int $percent): void
    {
        set_transient(self::key($type, $domain, $locale), ['percent' => $percent], self::TTL);
    }

    /** Call after any action that changes a domain+locale's translated strings or their total count. */
    public function invalidate(string $type, string $domain, string $locale): void
    {
        delete_transient(self::key($type, $domain, $locale));
    }

    private static function key(string $type, string $domain, string $locale): string
    {
        return self::PREFIX . md5("{$type}:{$domain}:{$locale}");
    }
}

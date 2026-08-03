<?php

namespace TransLangs\Language\Gettext;

/**
 * Option-backed status tracker for gettext translation files: for each
 * (component type, domain, target locale), whether a translation was found
 * online, imported by hand, or only a template was generated so far.
 *
 * A JSON option, not a DB table — this is a handful of rows for an admin
 * screen, not review-queue data; the stored status is a hint the REST layer
 * reconciles against the filesystem before trusting it (see
 * GettextDomainRestController), so this store never needs to detect or
 * repair staleness itself.
 */
final class GettextDomainRegistry
{
    public const OPTION = 'translangs_gettext_domains';

    public const STATUS_FOUND_ONLINE     = 'found_online';
    public const STATUS_IMPORTED         = 'imported';
    public const STATUS_TEMPLATE_PENDING = 'template_pending';

    /** @return array<string, array{domain: string, type: string, locale: string, status: string, updated_at: string}> */
    public function all(): array
    {
        $raw = json_decode((string) get_option(self::OPTION, '{}'), true);

        return is_array($raw) ? $raw : [];
    }

    /** @return array{domain: string, type: string, locale: string, status: string, updated_at: string}|null */
    public function get(string $type, string $domain, string $locale): ?array
    {
        return $this->all()[$this->key($type, $domain, $locale)] ?? null;
    }

    public function set(string $type, string $domain, string $locale, string $status): void
    {
        $map = $this->all();

        $map[$this->key($type, $domain, $locale)] = [
            'domain'     => $domain,
            'type'       => $type,
            'locale'     => $locale,
            'status'     => $status,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        update_option(self::OPTION, wp_json_encode($map));
    }

    /** Type included so a plugin and a theme sharing the same text domain never collide. */
    private function key(string $type, string $domain, string $locale): string
    {
        return $type . ':' . $domain . ':' . $locale;
    }
}

<?php

namespace LingoWP\GettextDomains;

final class GettextDomainRegistry
{
    public const OPTION = 'lingowp_gettext_domains';

    public const STATUS_FOUND_ONLINE     = 'found_online';
    public const STATUS_IMPORTED         = 'imported';
    public const STATUS_TEMPLATE_PENDING = 'template_pending';

    public function all(): array
    {
        $raw = json_decode((string) get_option(self::OPTION, '{}'), true);

        return is_array($raw) ? $raw : [];
    }

    public function get(string $type, string $domain, string $locale): ?array
    {
        return $this->all()[$this->key($type, $domain, $locale)] ?? null;
    }

    public function set(string $type, string $domain, string $locale, string $status, ?string $sourceLocale = null): void
    {
        $map = $this->all();

        $map[$this->key($type, $domain, $locale)] = [
            'domain'        => $domain,
            'type'          => $type,
            'locale'        => $locale,
            'status'        => $status,
            'updated_at'    => gmdate('Y-m-d H:i:s'),
            'source_locale' => $sourceLocale,
        ];

        update_option(self::OPTION, wp_json_encode($map));
    }

    private function key(string $type, string $domain, string $locale): string
    {
        return $type . ':' . $domain . ':' . $locale;
    }
}

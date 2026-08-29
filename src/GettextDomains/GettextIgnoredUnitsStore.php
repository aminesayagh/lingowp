<?php

namespace LingoWP\GettextDomains;

/**
 * Which scanned strings a translator explicitly chose to skip, per (type,
 * domain, locale) — the gettext equivalent of Content's "Ignore" status.
 * There's no DB row to flag here, so this is what GettextTranslationUnitsService
 * checks to leave a string out of the editable list, same effect (excluded
 * from translation) without a database.
 *
 * A JSON option, not a table — same reasoning as GettextDomainRegistry: a
 * handful of entries for an admin-only feature, not review-queue data.
 */
final class GettextIgnoredUnitsStore
{
    public const OPTION = 'lingowp_gettext_ignored_units';

    public function isIgnored(string $type, string $domain, string $locale, string $msgid, ?string $context): bool
    {
        $ignored = $this->all()[$this->groupKey($type, $domain, $locale)] ?? [];

        return in_array($this->unitKey($msgid, $context), $ignored, true);
    }

    public function ignore(string $type, string $domain, string $locale, string $msgid, ?string $context): void
    {
        $map       = $this->all();
        $groupKey  = $this->groupKey($type, $domain, $locale);
        $unitKey   = $this->unitKey($msgid, $context);
        $existing  = $map[$groupKey] ?? [];

        if (! in_array($unitKey, $existing, true)) {
            $existing[]      = $unitKey;
            $map[$groupKey]  = $existing;
            update_option(self::OPTION, wp_json_encode($map));
        }
    }

    /** @return array<string, string[]> groupKey => [unitKey, ...] */
    private function all(): array
    {
        $raw = json_decode((string) get_option(self::OPTION, '{}'), true);

        return is_array($raw) ? $raw : [];
    }

    private function groupKey(string $type, string $domain, string $locale): string
    {
        return $type . ':' . $domain . ':' . $locale;
    }

    /** Same key shape as WP core's Translation_Entry::key() — context + \x04 + msgid. */
    private function unitKey(string $msgid, ?string $context): string
    {
        return $context !== null && $context !== '' ? $context . "\x04" . $msgid : $msgid;
    }
}

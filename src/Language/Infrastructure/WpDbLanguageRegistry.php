<?php

namespace TransLangs\Language\Infrastructure;

use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\Language\Domain\LocaleNormalizer;

final class WpDbLanguageRegistry implements LanguageRegistry
{
    private ?array $cachedTargets = null;
    private ?array $cachedTargetDetails = null;

    public function __construct(
        private \wpdb             $wpdb,
        private LocaleNormalizer  $normalizer,
    ) {}

    public function getDefaultLanguage(): string
    {
        return $this->normalizer->current();
    }

    /** @return string[] */
    public function getTargetLanguages(): array
    {
        if ($this->cachedTargets !== null) {
            return $this->cachedTargets;
        }

        return $this->cachedTargets = array_keys($this->getTargetLanguageDetails());
    }

    /** @return array<string, array{name: string, native: string, dir: string}> */
    public function getTargetLanguageDetails(): array
    {
        if ($this->cachedTargetDetails !== null) {
            return $this->cachedTargetDetails;
        }

        $rows = (array) $this->wpdb->get_results( // phpcs:ignore WordPress.DB
            "SELECT code, name, native_name, direction
             FROM {$this->table()}
             WHERE status = 1
             ORDER BY sort_order ASC, code ASC",
            ARRAY_A
        );

        $details = [];

        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');

            if ($code === '') {
                continue;
            }

            $details[$code] = [
                'name'   => (string) ($row['name'] ?? $code),
                'native' => (string) ($row['native_name'] ?? ''),
                'dir'    => (string) ($row['direction'] ?? 'ltr'),
            ];
        }

        return $this->cachedTargetDetails = $details;
    }

    /** @return string[] */
    public function getEnabledLanguages(): array
    {
        return array_values(array_unique(
            array_merge([$this->getDefaultLanguage()], $this->getTargetLanguages())
        ));
    }

    public function isEnabledLanguage(string $code): bool
    {
        return $code !== '' && in_array($code, $this->getEnabledLanguages(), true);
    }

    public function enableLanguage(
        string $code,
        string $name,
        string $nativeName,
        string $direction,
        int    $sortOrder = 0
    ): void {
        $now = current_time('mysql');

        $this->wpdb->query( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "INSERT INTO {$this->table()}
                    (code, name, native_name, direction, status, sort_order, created_at, updated_at)
                 VALUES (%s, %s, %s, %s, 1, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    native_name = VALUES(native_name),
                    direction = VALUES(direction),
                    status = 1,
                    updated_at = VALUES(updated_at)",
                $code, $name, $nativeName, $direction, $sortOrder, $now, $now
            )
        );

        $this->cachedTargets = null;
        $this->cachedTargetDetails = null;
    }

    public function disableLanguage(string $code): void
    {
        $this->wpdb->delete($this->table(), ['code' => $code], ['%s']); // phpcs:ignore WordPress.DB
        $this->cachedTargets = null;
        $this->cachedTargetDetails = null;
    }

    /**
     * Seed target languages from the legacy translangs_enabled_languages option.
     * Called once during upgrade if the table is empty. Excludes the source language.
     *
     * @param array<string, array{name: string, native: string, dir: string}> $supportedLangs
     */
    public function seedFromLegacyOption(array $supportedLangs): void
    {
        $existing = $this->getTargetLanguages();
        if ($existing !== []) {
            return;
        }

        $source  = $this->getDefaultLanguage();
        $legacy  = (array) get_option('translangs_enabled_languages', []);

        foreach ($legacy as $code) {
            $code = (string) $code;
            if ($code === $source || $code === '' || !isset($supportedLangs[$code])) {
                continue;
            }
            $lang = $supportedLangs[$code];
            $this->enableLanguage($code, $lang['name'], $lang['native'], $lang['dir']);
        }
    }

    private function table(): string
    {
        return $this->wpdb->prefix . 'tl_languages';
    }
}

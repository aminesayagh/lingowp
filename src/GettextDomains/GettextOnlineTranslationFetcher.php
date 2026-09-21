<?php

namespace LingoWP\GettextDomains;

use LingoWP\Language\Domain\LocaleNormalizer;

final class GettextOnlineTranslationFetcher
{
    private GettextPoImporter $paths;

    public function __construct(GettextPoImporter $paths)
    {
        $this->paths = $paths;
    }

    public function fetch(string $type, string $slug, string $domain, string $version, string $locale): ?array
    {
        $this->maybeInstallCoreLocale($locale);

        $apiType  = $type === 'theme' ? 'themes' : 'plugins';
        $response = translations_api($apiType, ['slug' => $slug, 'version' => $version]);

        if (is_wp_error($response) || empty($response['translations']) || ! is_array($response['translations'])) {
            return null;
        }

        $picked = $this->pickPackages($response['translations'], $locale);
        if ($picked === null) {
            return null;
        }
        $exact        = $picked['exact'];
        $default      = $picked['default'];
        $defaultIsOwn = $exact !== null && $default['language'] === $locale;

        if ($defaultIsOwn) {
            $mo = $this->downloadMoFromPackage($exact['package'], $slug, $locale);

            return $mo !== null && $this->write($type, $domain, $locale, $mo)
                ? ['installed_locale' => $locale, 'own_region' => true, 'fallback_locale' => null]
                : null;
        }

        $defaultMo = $this->cachedDefaultRegionMo($type, $slug, $version, $default['language'], $default['package']);

        if ($exact === null) {
            return $defaultMo !== null && $this->write($type, $domain, $locale, $defaultMo)
                ? ['installed_locale' => $locale, 'own_region' => false, 'fallback_locale' => $default['language']]
                : null;
        }

        $ownMo = $this->downloadMoFromPackage($exact['package'], $slug, $locale);

        if ($ownMo === null) {
            return $defaultMo !== null && $this->write($type, $domain, $locale, $defaultMo)
                ? ['installed_locale' => $locale, 'own_region' => false, 'fallback_locale' => $default['language']]
                : null;
        }

        if ($defaultMo === null) {
            return $this->write($type, $domain, $locale, $ownMo)
                ? ['installed_locale' => $locale, 'own_region' => true, 'fallback_locale' => null]
                : null;
        }

        $merged = $this->mergeMo($defaultMo, $ownMo);

        return $merged !== null && $this->write($type, $domain, $locale, $merged)
            ? ['installed_locale' => $locale, 'own_region' => true, 'fallback_locale' => $default['language']]
            : null;
    }

    private function write(string $type, string $domain, string $locale, string $moBytes): bool
    {
        $target = $this->paths->targetPath($type, $domain, $locale);
        wp_mkdir_p(dirname($target));

        $staging = wp_tempnam(basename($target));
        if (file_put_contents($staging, $moBytes) === false) {
            @unlink($staging);
            return false;
        }

        if (! rename($staging, $target)) {
            @unlink($staging);
            return false;
        }

        return true;
    }

    private function pickPackages(array $translations, string $locale): ?array
    {
        $base = LocaleNormalizer::language($locale);
        if ($base === '') {
            return null;
        }

        $exact = null;
        foreach ($translations as $t) {
            if (($t['language'] ?? '') === $locale) {
                $exact = ['language' => $locale, 'package' => (string) ($t['package'] ?? '')];
                break;
            }
        }

        $siblings = array_values(array_filter(
            $translations,
            static fn (array $t): bool => LocaleNormalizer::language((string) ($t['language'] ?? '')) === $base
        ));
        if ($siblings === []) {
            return null;
        }

        usort($siblings, static function (array $a, array $b): int {
            $byDate = strcmp((string) ($b['updated'] ?? ''), (string) ($a['updated'] ?? ''));

            return $byDate !== 0 ? $byDate : strcmp((string) ($a['language'] ?? ''), (string) ($b['language'] ?? ''));
        });

        $best = null;
        foreach ($siblings as $sibling) {
            if (($sibling['language'] ?? '') !== $locale) {
                $best = $sibling;
                break;
            }
        }
        $best = $best ?? $siblings[0];

        return [
            'exact'   => $exact,
            'default' => ['language' => (string) $best['language'], 'package' => (string) ($best['package'] ?? '')],
        ];
    }

    private function mergeMo(string $baseBytes, string $overlayBytes): ?string
    {
        if (! class_exists('MO')) {
            require_once ABSPATH . WPINC . '/pomo/mo.php';
        }

        $base    = $this->parseMoBytes($baseBytes);
        $overlay = $this->parseMoBytes($overlayBytes);
        if ($base === null || $overlay === null) {
            return null;
        }

        $merged = new \MO();
        $merged->set_headers(array_merge((array) $base->headers, (array) $overlay->headers));

        foreach ($base->entries as $entry) {
            $merged->add_entry($entry);
        }
        foreach ($overlay->entries as $entry) {
            if ((string) ($entry->translations[0] ?? '') === '') {
                continue;
            }
            $merged->add_entry($entry);
        }

        return $merged->export();
    }

    private function parseMoBytes(string $bytes): ?\MO
    {
        $tmp = wp_tempnam('gettext-merge.mo');
        if (file_put_contents($tmp, $bytes) === false) {
            @unlink($tmp);
            return null;
        }

        $mo = new \MO();
        $ok = $mo->import_from_file($tmp);
        @unlink($tmp);

        return $ok ? $mo : null;
    }

    private function cachedDefaultRegionMo(string $type, string $slug, string $version, string $defaultLocale, string $packageUrl): ?string
    {
        $key    = 'lingowp_gettext_pack_' . md5("{$type}:{$slug}:{$version}:{$defaultLocale}");
        $cached = $this->readPackCache($key);
        if ($cached !== null) {
            return $cached;
        }

        $mo = $this->downloadMoFromPackage($packageUrl, $slug, $defaultLocale);
        if ($mo === null) {
            return null;
        }

        $this->writePackCache($key, $mo);

        return $mo;
    }

    private function readPackCache(string $key): ?string
    {
        $blob = get_transient($key);
        if (! is_string($blob) || $blob === '') {
            return null;
        }

        $mo = gzdecode($blob);

        return $mo === false ? null : $mo;
    }

    private function writePackCache(string $key, string $moBytes): void
    {
        $blob = gzencode($moBytes, 6);
        if ($blob !== false) {
            set_transient($key, $blob, HOUR_IN_SECONDS);
        }
    }

    private function maybeInstallCoreLocale(string $locale): void
    {
        try {
            if (! function_exists('wp_download_language_pack')) {
                require_once ABSPATH . 'wp-admin/includes/translation-install.php';
            }
            if (! function_exists('request_filesystem_credentials')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            wp_download_language_pack($locale);
        } catch (\Throwable $e) {
        }
    }

    private function downloadMoFromPackage(string $packageUrl, string $slug, string $locale): ?string
    {
        if ($packageUrl === '' || !class_exists(\ZipArchive::class)) {
            return null;
        }

        $response = wp_remote_get($packageUrl, ['timeout' => 30]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $zipTemp = wp_tempnam('gettext-language-pack.zip');
        if (file_put_contents($zipTemp, wp_remote_retrieve_body($response)) === false) {
            @unlink($zipTemp);
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipTemp) !== true) {
            @unlink($zipTemp);
            return null;
        }

        $mo = $zip->getFromName($slug . '-' . $locale . '.mo');
        $zip->close();
        @unlink($zipTemp);

        return $mo !== false ? $mo : null;
    }
}

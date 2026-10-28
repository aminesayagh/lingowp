<?php

namespace LingoWP\GettextDomains;

use LingoWP\Language\Domain\LocaleNormalizer;

/**
 * Installs a wordpress.org community translation for one plugin/theme +
 * locale.
 *
 * Does NOT use `Language_Pack_Upgrader`/`WP_Filesystem` to install the found
 * package — confirmed on this install that it's unreliable outside a real
 * interactive wp-admin session: `wp-content/languages/` doesn't exist yet on
 * a fresh site, `Language_Pack_Upgrader::run()` needs `WP_Filesystem` to
 * create it, and there is no way to supply FTP credentials from a REST call
 * if direct filesystem access isn't already configured. That's what was
 * silently swallowing every "find online" attempt, for every plugin, not
 * just one — `translations_api()` itself was finding the translation fine.
 *
 * Instead: `translations_api()` still finds the package (its `language`
 * field is already a correct WordPress locale code, e.g. `fr_FR` — no
 * GlotPress URL-slug mapping to get wrong, e.g. `pt_BR` vs `pt-br`), then the
 * package `.zip` (a small, plain archive on downloads.wordpress.org
 * containing `{slug}-{locale}.po/.mo/.l10n.php`) is fetched with
 * `wp_remote_get()` and only the `.mo` is extracted with PHP's built-in
 * `ZipArchive` — same plain-file write GettextPoImporter already uses for a
 * user-uploaded `.po`, no WP_Filesystem involved anywhere.
 */
final class GettextOnlineTranslationFetcher
{
    private GettextPoImporter $paths;

    public function __construct(GettextPoImporter $paths)
    {
        $this->paths = $paths;
    }

    /**
     * $slug is the wordpress.org package slug (plugin folder / theme
     * stylesheet) — it names the `translations_api()` lookup and the `.mo`
     * entry inside the downloaded zip. $domain is the text domain WordPress
     * actually resolves translations by (WP_Textdomain_Registry globs for
     * "$domain-$locale.mo"), so the installed file is written under $domain,
     * not $slug. They're the same string for most directory-hosted
     * components, but not all.
     *
     * Language-first, not strictly region-first. For a requested regional
     * locale (en_NZ) two candidate packages are resolved from the API list:
     *   - the region's own package, if wordpress.org has one;
     *   - the language's "default region" package = the freshest
     *     (updated desc) same-base-language package. There is no CLDR /
     *     likely-subtags data to infer a canonical region from, so "what the
     *     community maintains most" stands in for it.
     * Then:
     *   - own package + a different default region -> download both, merge
     *     (own strings win, gaps filled from the default region — never left
     *     as the English source), write the blend under en_NZ's path;
     *   - no own package -> install the default-region package under en_NZ's
     *     path (the long-standing fallback, now explicit);
     *   - the region IS the default -> just its own package.
     * Exactly one `.mo` is written per call, always the requested locale's.
     * The default-region package is cached (transient, keyed by
     * type/slug/version/default-locale) so finding online for a dozen regions
     * of one language downloads that package once, not a dozen times.
     *
     * @param 'plugin'|'theme' $type
     * @return array{installed_locale: string, own_region: bool, fallback_locale: ?string}|null
     *         null if nothing was found/installed. `own_region` is true when
     *         wordpress.org had a package for $locale itself (with or without a
     *         gap-fill merge); `fallback_locale` is the default-region locale
     *         used as the gap-filler, or null.
     */
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

        // Case A — the requested region is itself the default region.
        if ($defaultIsOwn) {
            $mo = $this->downloadMoFromPackage($exact['package'], $slug, $locale);

            return $mo !== null && $this->write($type, $domain, $locale, $mo)
                ? ['installed_locale' => $locale, 'own_region' => true, 'fallback_locale' => null]
                : null;
        }

        $defaultMo = $this->cachedDefaultRegionMo($type, $slug, $version, $default['language'], $default['package']);

        // Case B — no own package; install the default region under the
        // requested path (WordPress resolves purely by filename).
        if ($exact === null) {
            return $defaultMo !== null && $this->write($type, $domain, $locale, $defaultMo)
                ? ['installed_locale' => $locale, 'own_region' => false, 'fallback_locale' => $default['language']]
                : null;
        }

        // Case C — own package plus a different default region: merge, own
        // strings winning, default region filling the gaps.
        $ownMo = $this->downloadMoFromPackage($exact['package'], $slug, $locale);

        if ($ownMo === null) {
            // Own package listed but its download/extraction failed. Ship the
            // default region alone rather than nothing — still beats leaving
            // the user on the English source.
            return $defaultMo !== null && $this->write($type, $domain, $locale, $defaultMo)
                ? ['installed_locale' => $locale, 'own_region' => false, 'fallback_locale' => $default['language']]
                : null;
        }

        if ($defaultMo === null) {
            // Default-region download failed — the region's own translation
            // still beats the English source, so ship it alone.
            return $this->write($type, $domain, $locale, $ownMo)
                ? ['installed_locale' => $locale, 'own_region' => true, 'fallback_locale' => null]
                : null;
        }

        $merged = $this->mergeMo($defaultMo, $ownMo);

        return $merged !== null && $this->write($type, $domain, $locale, $merged)
            ? ['installed_locale' => $locale, 'own_region' => true, 'fallback_locale' => $default['language']]
            : null;
    }

    /** Atomic write of `.mo` bytes to a domain+locale's standard path. */
    private function write(string $type, string $domain, string $locale, string $moBytes): bool
    {
        $target = $this->paths->targetPath($type, $domain, $locale);
        wp_mkdir_p(dirname($target));

        $staging = wp_tempnam(basename($target));
        if (file_put_contents($staging, $moBytes) === false) {
            wp_delete_file($staging);
            return false;
        }

        if (! rename($staging, $target)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap, so WordPress never loads a half-written .mo; the target is Linvara's own WP_LANG_DIR/lingowp folder (see GettextPoImporter::baseDir()).
            wp_delete_file($staging);
            return false;
        }

        return true;
    }

    /**
     * The region's own package (if any) plus the language's default-region
     * package, resolved from one API list. Null when the API offers nothing
     * in the requested base language at all.
     *
     * @param array<int, array<string, mixed>> $translations
     * @return array{exact: ?array{language: string, package: string}, default: array{language: string, package: string}}|null
     */
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

        // Freshest wins; language asc is a deterministic tie-break because
        // usort isn't stable pre-PHP 8.0 and wp.org bulk imports share the
        // exact `updated` timestamp across regions.
        usort($siblings, static function (array $a, array $b): int {
            $byDate = strcmp((string) ($b['updated'] ?? ''), (string) ($a['updated'] ?? ''));

            return $byDate !== 0 ? $byDate : strcmp((string) ($a['language'] ?? ''), (string) ($b['language'] ?? ''));
        });

        // The default region is the freshest sibling OTHER THAN the requested
        // locale — otherwise a region that just happens to carry the most
        // recent GlotPress timestamp (a stray recent contribution to fr_BE
        // over fr_FR) becomes its own "default", collapses to case A, and
        // never gets gap-filled from the language's main translation. Falls
        // back to the sole sibling only when the requested locale is the one
        // and only region for its language (nothing else to merge with).
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

    /**
     * Merge two `.mo` byte blobs: $overlayBytes wins per string, $baseBytes
     * fills every gap. Pure — only touches wp_tempnam() scratch files, writes
     * nothing to WP_LANG_DIR. Null if either blob fails to parse.
     */
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

        // The file is written for the requested (overlay) locale, so its own
        // headers win — Language, PO-Revision-Date, etc.; the base only fills
        // header keys the overlay is missing. (Plural-Forms is language-scoped
        // and identical across same-base regions either way.)
        $merged = new \MO();
        $merged->set_headers(array_merge((array) $base->headers, (array) $overlay->headers));

        foreach ($base->entries as $entry) {
            $merged->add_entry($entry);
        }
        foreach ($overlay->entries as $entry) {
            // Skip an overlay entry with no translation so a regional `.po`
            // that ships an untranslated string can't blank a good base one.
            if ((string) ($entry->translations[0] ?? '') === '') {
                continue;
            }
            $merged->add_entry($entry); // replaces by Translation_Entry::key()
        }

        return $merged->export();
    }

    private function parseMoBytes(string $bytes): ?\MO
    {
        $tmp = wp_tempnam('gettext-merge.mo');
        if (file_put_contents($tmp, $bytes) === false) {
            wp_delete_file($tmp);
            return null;
        }

        $mo = new \MO();
        $ok = $mo->import_from_file($tmp);
        wp_delete_file($tmp);

        return $ok ? $mo : null;
    }

    /**
     * The default-region `.mo` bytes, from an hour-long transient keyed by
     * component + version + default locale. This is what makes "find online
     * for 12 regions of one language" download the default package once.
     * Stored gzip-compressed (a WooCommerce `.mo` is ~1 MB raw); the
     * transient rows are non-autoloaded.
     */
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

    /**
     * Best-effort: ensures switch_to_locale($locale) is legal at all
     * (installing a plugin/theme pack alone would not satisfy
     * get_available_languages()). This one still has to go through
     * wp_download_language_pack() — WordPress core's own function, not
     * something we can route around — which pulls in Language_Pack_Upgrader
     * and WP_Filesystem the same way the old plugin/theme path did. Unlike
     * that path, this one is NOT rewritten, since it targets WP_LANG_DIR
     * directly rather than a specific plugin/theme file we already know how
     * to write ourselves.
     *
     * Confirmed on this install: called from a REST request,
     * wp-admin/includes/file.php (where request_filesystem_credentials()
     * lives) is never loaded, so the call fatals with "Call to undefined
     * function" instead of returning a graceful WP_Error — crashing the
     * whole request. Requiring that file first fixes the immediate crash;
     * wrapping in try/catch on top is what actually makes this "best-effort"
     * true, since Language_Pack_Upgrader's filesystem step can still throw
     * or misbehave on hosting setups this call was never meant to depend on.
     */
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
            // Genuinely best-effort — the plugin/theme .mo below is what
            // matters; switch_to_locale() simply won't take effect until the
            // core locale exists by some other means (e.g. Settings >
            // General > Site Language).
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
            wp_delete_file($zipTemp);
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipTemp) !== true) {
            wp_delete_file($zipTemp);
            return null;
        }

        $mo = $zip->getFromName($slug . '-' . $locale . '.mo');
        $zip->close();
        wp_delete_file($zipTemp);

        return $mo !== false ? $mo : null;
    }
}

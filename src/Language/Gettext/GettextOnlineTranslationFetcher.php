<?php

namespace TransLangs\Language\Gettext;

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
    public function __construct(private GettextPoImporter $paths) {}

    /**
     * Note: the installed file is named after $slug (matching the package's
     * own convention), while WordPress resolves translations by text
     * $domain (confirmed via WP_Textdomain_Registry::get_path_from_lang_dir(),
     * which globs for "$domain-$locale.mo"). These are the same string for
     * the overwhelming majority of directory-hosted plugins/themes; this is
     * an inherent property of WordPress's own language-pack system, not
     * something this class introduces.
     *
     * @param 'plugin'|'theme' $type
     * @return bool True if a translation for this component + locale was
     *              found and installed.
     */
    public function fetch(string $type, string $slug, string $version, string $locale): bool
    {
        $this->maybeInstallCoreLocale($locale);

        $apiType  = $type === 'theme' ? 'themes' : 'plugins';
        $response = translations_api($apiType, ['slug' => $slug, 'version' => $version]);

        if (is_wp_error($response) || empty($response['translations']) || ! is_array($response['translations'])) {
            return false;
        }

        $package = '';
        foreach ($response['translations'] as $translation) {
            if (($translation['language'] ?? '') === $locale) {
                $package = (string) ($translation['package'] ?? '');
                break;
            }
        }
        if ($package === '') {
            return false;
        }

        $mo = $this->downloadMoFromPackage($package, $slug, $locale);
        if ($mo === null) {
            return false;
        }

        $target = $this->paths->targetPath($type, $slug, $locale);
        wp_mkdir_p(dirname($target));

        $staging = wp_tempnam(basename($target));
        if (file_put_contents($staging, $mo) === false) {
            @unlink($staging);
            return false;
        }

        return rename($staging, $target);
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

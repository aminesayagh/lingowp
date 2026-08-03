<?php

namespace TransLangs\Language\Gettext;

/**
 * Installs a wordpress.org community translation for one plugin/theme +
 * locale, using WordPress core's own language-pack machinery — no custom
 * PO/MO parsing or HTTP calls of our own.
 *
 * Two distinct steps, not one call:
 *   1. `wp_download_language_pack()` installs the CORE locale file, which is
 *      what `switch_to_locale()` requires to exist before it will switch at
 *      all. This function only ever installs a core pack — confirmed by
 *      reading its source, which hardcodes `$translation->type = 'core'` —
 *      so it cannot be used for step 2.
 *   2. `translations_api()` + `Language_Pack_Upgrader::bulk_upgrade()` installs
 *      the actual plugin/theme translation package, shaped the same way
 *      `wp_get_translation_updates()` shapes core's own scheduled translation
 *      updates (confirmed by reading both `class-language-pack-upgrader.php`
 *      and `wp-includes/update.php` on this install).
 */
final class GettextOnlineTranslationFetcher
{
    /**
     * Note: the installed file is named after $slug (matching
     * Language_Pack_Upgrader's own convention), while WordPress resolves
     * translations by text $domain (confirmed via
     * WP_Textdomain_Registry::get_path_from_lang_dir(), which globs for
     * "$domain-$locale.mo"). These are the same string for the overwhelming
     * majority of directory-hosted plugins/themes; this is an inherent
     * property of WordPress's own language-pack system, not something this
     * class introduces.
     *
     * @param 'plugin'|'theme' $type
     * @return bool True if a translation for this component + locale was
     *              found and installed.
     */
    public function fetch(string $type, string $slug, string $version, string $locale): bool
    {
        if (! function_exists('wp_download_language_pack')) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }

        // Ensures switch_to_locale($locale) is legal at all; installing a
        // plugin/theme pack alone would not satisfy get_available_languages().
        wp_download_language_pack($locale);

        $apiType  = $type === 'theme' ? 'themes' : 'plugins';
        $response = translations_api($apiType, ['slug' => $slug, 'version' => $version]);

        if (is_wp_error($response) || empty($response['translations']) || ! is_array($response['translations'])) {
            return false;
        }

        $match = null;
        foreach ($response['translations'] as $translation) {
            if (($translation['language'] ?? '') === $locale) {
                $match = $translation;
                break;
            }
        }
        if ($match === null) {
            return false;
        }

        if (! class_exists('Language_Pack_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/class-language-pack-upgrader.php';
        }

        // Shape matches what wp_get_translation_updates() builds for core's
        // own background translation updates: the per-translation fields
        // from the API response, plus the component identity the API call
        // itself doesn't echo back.
        $languageUpdate = (object) array_merge($match, [
            'type' => $type,
            'slug' => $slug,
        ]);

        $upgrader = new \Language_Pack_Upgrader(new \Automatic_Upgrader_Skin());
        $result   = $upgrader->bulk_upgrade([$languageUpdate]);

        return is_array($result) && ! empty($result[0]) && ! is_wp_error($result[0]);
    }
}

<?php

namespace LingoWP\GettextDomains;

/**
 * Points WordPress at the `.mo` files LingoWP writes under its own folder,
 * `uploads/linvara/languages/{plugins,themes}/`, instead of the shared
 * `wp-content/languages/{plugins,themes}/` that core's Language_Pack_Upgrader
 * owns and overwrites on every translation update. Same model as Loco
 * Translate's "custom" location: our files never collide with core's, and a
 * language-pack update can no longer erase an admin's work.
 *
 * Two hooks, because WordPress changed how it finds translations:
 *
 *   lang_dir_for_domain (6.6+)     The textdomain registry asks which directory
 *                                  holds `$domain-$locale.mo` before touching
 *                                  the filesystem. Answering here is what makes
 *                                  a LingoWP file load for a domain that has no
 *                                  core language pack at all — on 6.7+ this is
 *                                  the only path, load_plugin_textdomain() no
 *                                  longer loads anything itself.
 *   load_textdomain_mofile (2.9+)  Fires for every explicit load_textdomain()
 *                                  call, before any existence check. Covers
 *                                  6.2–6.5 and plugins that load their own
 *                                  file directly. Only the 6.2–6.5 case of a
 *                                  just-in-time load with no core pack is out
 *                                  of reach; those sites fall back to core's
 *                                  own lookup.
 *
 * A file in our folder wins whenever it exists; otherwise both filters return
 * what they were given, so nothing changes for domains LingoWP never touched.
 */
final class GettextMoFileLoader
{
    private const SUBDIRS = ['plugins', 'themes'];

    public function register(): void
    {
        add_filter('lang_dir_for_domain', [$this, 'langDir'], 10, 3);
        add_filter('load_textdomain_mofile', [$this, 'moFile'], 10, 2);
    }

    /**
     * @param string|false $path
     * @return string|false Trailing-slashed, as the registry expects.
     */
    public function langDir($path, string $domain, string $locale)
    {
        foreach (self::SUBDIRS as $sub) {
            $dir = GettextPoImporter::baseDir() . '/' . $sub . '/';
            if (is_readable($dir . $domain . '-' . $locale . '.mo')) {
                return $dir;
            }
        }

        return $path;
    }

    public function moFile(string $mofile, string $domain): string
    {
        // Core names the file "$domain-$locale.mo" under WP_LANG_DIR but just
        // "$locale.mo" inside a theme's own languages folder; recover the
        // locale from either shape rather than trusting the prefix.
        $file   = basename($mofile, '.mo');
        $locale = strpos($file, $domain . '-') === 0 ? substr($file, strlen($domain) + 1) : $file;
        if ($locale === '') {
            return $mofile;
        }

        foreach (self::SUBDIRS as $sub) {
            $own = GettextPoImporter::baseDir() . '/' . $sub . '/' . $domain . '-' . $locale . '.mo';
            if (is_readable($own)) {
                return $own;
            }
        }

        return $mofile;
    }
}

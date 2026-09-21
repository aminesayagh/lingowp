<?php

namespace LingoWP\GettextDomains;

final class GettextMoFileLoader
{
    private const SUBDIRS = ['plugins', 'themes'];

    public function register(): void
    {
        add_filter('lang_dir_for_domain', [$this, 'langDir'], 10, 3);
        add_filter('load_textdomain_mofile', [$this, 'moFile'], 10, 2);
    }

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

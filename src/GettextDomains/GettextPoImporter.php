<?php

namespace LingoWP\GettextDomains;

/**
 * Compiles an uploaded `.po` file into the standard WordPress `.mo` path for
 * a plugin/theme domain + locale, using WordPress's own bundled PO/MO
 * classes (`wp-includes/pomo/po.php`, `mo.php`) — no PO-parsing dependency
 * of our own.
 *
 * Files land under Linvara's own `uploads/linvara/languages/` (see
 * baseDir()), never in the shared `wp-content/languages/{plugins,themes}/`
 * that core's Language_Pack_Upgrader overwrites on every translation update.
 * GettextMoFileLoader tells WordPress to look there.
 */
final class GettextPoImporter
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @param 'plugin'|'theme' $type
     */
    public function import(string $tmpPath, string $type, string $domain, string $locale): bool
    {
        if (! $this->isAcceptableUpload($tmpPath)) {
            return false;
        }

        if (! class_exists('PO')) {
            require_once ABSPATH . WPINC . '/pomo/po.php';
        }
        if (! class_exists('MO')) {
            require_once ABSPATH . WPINC . '/pomo/mo.php';
        }

        $po = new \PO();
        if (! $po->import_from_file($tmpPath) || $po->entries === []) {
            return false;
        }

        $mo          = new \MO();
        $mo->headers = $po->headers;

        foreach ($po->entries as $entry) {
            // Fuzzy entries are the PO parser's own uncertainty marker —
            // never compile one into the live translation by default.
            if (in_array('fuzzy', $entry->flags, true)) {
                continue;
            }
            $mo->add_entry($entry);
        }

        $target = $this->targetPath($type, $domain, $locale);
        wp_mkdir_p(dirname($target));

        $staging = wp_tempnam(basename($target));
        if (! $mo->export_to_file($staging)) {
            wp_delete_file($staging);
            return false;
        }

        // Rename-into-place: a concurrent request never reads a half-written file.
        return rename($staging, $target); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap, so WordPress never loads a half-written .mo; the target is Linvara's own uploads/linvara/languages folder (see GettextPoImporter::baseDir()).
    }

    /**
     * Root of every `.mo` Linvara writes: its own folder in uploads, the only
     * place the WordPress.org guidelines let a plugin store files (an earlier
     * WP_LANG_DIR/lingowp subfolder was rejected in review). Core's language
     * packs never touch uploads, and on multisite every site gets its own.
     * GettextMoFileLoader points WordPress here. Removed whole on uninstall.
     */
    public static function baseDir(): string
    {
        return wp_upload_dir(null, false)['basedir'] . '/linvara/languages';
    }

    /** Where 1.6.3 and earlier wrote; emptied by migrateLegacyDir(). */
    public static function legacyDir(): string
    {
        return WP_LANG_DIR . '/lingowp';
    }

    /**
     * One-time move of the files written before baseDir() moved to uploads.
     * They are the only copy of hand-saved and imported strings, so they are
     * moved, never dropped: the old folder is deleted only once every file has
     * landed. A file already in the new folder is newer and wins. Main site
     * only — the old folder was shared by every site of a network.
     */
    public static function migrateLegacyDir(): void
    {
        $old = self::legacyDir();
        if (! is_dir($old) || ! is_main_site()) {
            return;
        }

        $allMoved = true;
        foreach (['plugins', 'themes'] as $sub) {
            foreach (glob($old . '/' . $sub . '/*.mo') ?: [] as $file) {
                $target = self::baseDir() . '/' . $sub . '/' . basename($file);
                if (file_exists($target)) {
                    continue;
                }
                wp_mkdir_p(dirname($target));
                // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moving Linvara's own files out of the old location into uploads.
                $allMoved = rename($file, $target) && $allMoved;
            }
        }

        // ponytail: a failed move leaves the old folder in place and retries on
        // the next main-site request — one is_dir() + glob() until it succeeds.
        if ($allMoved) {
            self::removeTree($old);
        }
    }

    /** Recursively delete a folder Linvara owns. */
    public static function removeTree(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions -- deleting the plugin's own files
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    /** @param 'plugin'|'theme' $type */
    public function targetPath(string $type, string $domain, string $locale): string
    {
        $sub = $type === 'theme' ? 'themes' : 'plugins';

        return self::baseDir() . '/' . $sub . '/' . $domain . '-' . $locale . '.mo';
    }

    /** The currently installed `.mo` at $path, re-exported as a `.po`, or null if it doesn't exist. */
    public function exportAsPo(string $path): ?\PO
    {
        if (! file_exists($path)) {
            return null;
        }

        if (! class_exists('MO')) {
            require_once ABSPATH . WPINC . '/pomo/mo.php';
        }
        if (! class_exists('PO')) {
            require_once ABSPATH . WPINC . '/pomo/po.php';
        }

        $mo = new \MO();
        $mo->import_from_file($path);

        $po          = new \PO();
        $po->headers = $mo->headers;
        foreach ($mo->entries as $entry) {
            $po->add_entry($entry);
        }

        return $po;
    }

    private function isAcceptableUpload(string $tmpPath): bool
    {
        if ($tmpPath === '' || ! is_uploaded_file($tmpPath)) {
            return false;
        }

        $size = filesize($tmpPath);

        return $size !== false && $size > 0 && $size <= self::MAX_BYTES;
    }
}

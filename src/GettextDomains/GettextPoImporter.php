<?php

namespace LingoWP\GettextDomains;

/**
 * Compiles an uploaded `.po` file into the standard WordPress `.mo` path for
 * a plugin/theme domain + locale, using WordPress's own bundled PO/MO
 * classes (`wp-includes/pomo/po.php`, `mo.php`) — no PO-parsing dependency
 * of our own.
 *
 * Files land under LingoWP's own `wp-content/languages/lingowp/` (see
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
        return rename($staging, $target); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap, so WordPress never loads a half-written .mo; the target is LingoWP's own WP_LANG_DIR/lingowp folder (see GettextPoImporter::baseDir()).
    }

    /**
     * Root of every `.mo` LingoWP writes. A subfolder of WP_LANG_DIR because
     * that is the directory WordPress reads translations from and the one its
     * own installer writes to; a subfolder of it because that is the only way
     * to never collide with a file core owns. Removed whole on uninstall.
     */
    public static function baseDir(): string
    {
        return WP_LANG_DIR . '/lingowp';
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

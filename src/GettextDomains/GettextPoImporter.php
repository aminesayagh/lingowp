<?php

namespace LingoWP\GettextDomains;

final class GettextPoImporter
{
    private const MAX_BYTES = 5 * 1024 * 1024;

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
            if (in_array('fuzzy', $entry->flags, true)) {
                continue;
            }
            $mo->add_entry($entry);
        }

        $target = $this->targetPath($type, $domain, $locale);
        wp_mkdir_p(dirname($target));

        $staging = wp_tempnam(basename($target));
        if (! $mo->export_to_file($staging)) {
            @unlink($staging);
            return false;
        }

        return rename($staging, $target);
    }

    public static function baseDir(): string
    {
        return WP_LANG_DIR . '/lingowp';
    }

    public function targetPath(string $type, string $domain, string $locale): string
    {
        $sub = $type === 'theme' ? 'themes' : 'plugins';

        return self::baseDir() . '/' . $sub . '/' . $domain . '-' . $locale . '.mo';
    }

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

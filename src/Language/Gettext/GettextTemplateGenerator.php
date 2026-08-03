<?php

namespace TransLangs\Language\Gettext;

use FilesystemIterator;
use Gettext\Translations;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TenQuality\Gettext\Scanner\WPPhpScanner;
use Throwable;

/**
 * Scans a plugin/theme's own PHP source for gettext calls with a real
 * AST-based scanner (`10quality/gettext-wp-scanner`, wrapping
 * `nikic/php-parser` — the same parser family WP-CLI's own i18n tooling uses).
 * No WP-CLI dependency, no shell-exec.
 *
 * Reached whenever the in-app translation editor needs the full list of a
 * component's strings (see GettextTranslationUnitsService) — not just as a
 * last resort, since it's how "translate manually" gets its row list.
 */
final class GettextTemplateGenerator
{
    private const SKIP_DIRS      = ['vendor', 'node_modules', 'tests', 'test'];
    private const MAX_FILES      = 5000;
    private const MAX_FILE_BYTES = 2 * 1024 * 1024;

    /**
     * @return array<int, array{msgid: string, context: ?string}>|null Null if
     *         $path has no source directory to scan (e.g. a single-file plugin).
     */
    public function scan(string $path, string $domain): ?array
    {
        if ($path === '' || ! is_dir($path)) {
            return null;
        }

        $translations = Translations::create($domain);

        $scanner = new WPPhpScanner($translations);
        $scanner->setDefaultDomain($domain);
        // A single malformed call site (e.g. a dynamic domain argument) must
        // not abort the whole scan.
        $scanner->ignoreInvalidFunctions(true);

        $count = 0;
        foreach ($this->phpFiles($path) as $file) {
            if (++$count > self::MAX_FILES) {
                break;
            }

            $size = @filesize($file);
            if ($size === false || $size > self::MAX_FILE_BYTES) {
                continue;
            }

            try {
                $scanner->scanFile($file);
            } catch (Throwable $e) {
                continue;
            }
        }

        $entries = [];
        foreach ($translations as $translation) {
            $entries[] = ['msgid' => $translation->getOriginal(), 'context' => $translation->getContext()];
        }

        return $entries;
    }

    /** @return \Generator<string> */
    private function phpFiles(string $root): \Generator
    {
        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file, string $key, RecursiveDirectoryIterator $iterator): bool {
                if ($file->isLink()) {
                    return false;
                }
                if ($iterator->hasChildren()) {
                    return ! in_array(strtolower($file->getFilename()), self::SKIP_DIRS, true);
                }

                return strtolower($file->getExtension()) === 'php';
            }
        );

        foreach (new RecursiveIteratorIterator($filter) as $file) {
            yield $file->getPathname();
        }
    }
}

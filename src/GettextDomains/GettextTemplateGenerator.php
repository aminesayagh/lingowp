<?php

namespace LingoWP\GettextDomains;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use LingoWP\GettextDomains\Scanner\PhpGettextScanner;
use Throwable;

/**
 * Scans a plugin/theme's own PHP source for gettext calls with PHP's own
 * tokenizer (PhpGettextScanner — the approach of WP-CLI's `wp i18n make-pot`).
 * No WP-CLI dependency, no shell-exec, no bundled parser library.
 *
 * Reached whenever the in-app translation editor needs the full list of a
 * component's strings (see GettextTranslationUnitsService) — not just as a
 * last resort, since it's how "translate manually" gets its row list.
 */
class GettextTemplateGenerator
{
    private const SKIP_DIRS      = ['vendor', 'node_modules', 'tests', 'test'];
    private const MAX_FILES      = 5000;
    private const MAX_FILE_BYTES = 2 * 1024 * 1024;

    private ?bool $working = null;

    /**
     * @return array<int, array{msgid: string, context: ?string}>|null Null if
     *         $path has no source directory to scan (e.g. a single-file plugin).
     */
    public function scan(string $path, string $domain): ?array
    {
        if ($path === '' || ! is_dir($path)) {
            return null;
        }

        $scanner = new PhpGettextScanner();
        $entries = [];
        $count   = 0;
        foreach ($this->phpFiles($path) as $file) {
            if (++$count > self::MAX_FILES) {
                break;
            }

            $size = @filesize($file);
            if ($size === false || $size > self::MAX_FILE_BYTES) {
                continue;
            }

            $code = @file_get_contents($file);
            if ($code === false) {
                continue;
            }

            try {
                foreach ($scanner->scan($code, $domain) as $entry) {
                    $entries[($entry['context'] === null ? '' : $entry['context'] . "\x04") . $entry['msgid']] = $entry;
                }
            } catch (Throwable $e) {
                continue; // one unreadable file must not abort the whole scan
            }
        }

        return array_values($entries);
    }

    /**
     * Whether the scanner can find strings at all in this request — checked
     * on a one-line snippet before an empty scan result is trusted. A broken
     * scanner looks exactly like "this component has no strings", and that
     * must never be cached as a finished scan. (1.6.5 shipped a parser
     * library another plugin could replace at runtime; the tokenizer
     * scanner has no such dependency, the check stays as a cheap guard.)
     * Memoized per instance (one per request, see Plugin.php). Not final so
     * a test can stand in a broken scanner.
     */
    public function isWorking(): bool
    {
        if ($this->working !== null) {
            return $this->working;
        }

        try {
            $found = (new PhpGettextScanner())->scan("<?php __('Self check', 'lingowp-selfcheck');", 'lingowp-selfcheck');
        } catch (Throwable $e) {
            return $this->working = false;
        }

        return $this->working = $found === [['msgid' => 'Self check', 'context' => null]];
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

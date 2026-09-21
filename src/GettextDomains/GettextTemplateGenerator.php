<?php

namespace LingoWP\GettextDomains;

use FilesystemIterator;
use Gettext\Translations;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use LingoWP\GettextDomains\Scanner\WordPressPhpScanner;
use Throwable;

final class GettextTemplateGenerator
{
    private const SKIP_DIRS      = ['vendor', 'node_modules', 'tests', 'test'];
    private const MAX_FILES      = 5000;
    private const MAX_FILE_BYTES = 2 * 1024 * 1024;

    public function scan(string $path, string $domain): ?array
    {
        if ($path === '' || ! is_dir($path)) {
            return null;
        }

        $translations = Translations::create($domain);

        $scanner = new WordPressPhpScanner($translations);
        $scanner->setDefaultDomain($domain);
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

<?php

namespace LingoWP\GettextDomains;

final class GettextTranslationUnitsService
{
    private GettextTemplateGenerator $scanner;
    private GettextPoImporter $paths;
    private GettextUnitRepository $repository;

    public function __construct(
        GettextTemplateGenerator $scanner,
        GettextPoImporter $paths,
        GettextUnitRepository $repository
    ) {
        $this->scanner    = $scanner;
        $this->paths      = $paths;
        $this->repository = $repository;
    }

    public function list(string $path, string $type, string $domain, string $locale, string $search = ''): ?array
    {
        if (! $this->ensureScanned($path, $type, $domain)) {
            return null;
        }

        $existing = $this->readExisting($type, $domain, $locale);

        $units = [];
        foreach ($this->repository->activeUnits($type, $domain, $search) as $entry) {
            $units[] = [
                'msgid'   => $entry['msgid'],
                'context' => $entry['context'],
                'msgstr'  => $existing[$this->moKey($entry['msgid'], $entry['context'])] ?? '',
            ];
        }

        return $units;
    }

    public function ensureComponentScanned(string $path, string $type, string $domain): bool
    {
        return $this->ensureScanned($path, $type, $domain);
    }

    public function wasComponentScanned(string $type, string $domain): bool
    {
        return $this->repository->wasScanned($type, $domain);
    }

    public function translatedCount(string $path, string $type, string $domain, string $locale): ?array
    {
        if (! $this->ensureScanned($path, $type, $domain)) {
            return null;
        }

        $total = $this->repository->countActive($type, $domain);
        if ($total === 0) {
            return null;
        }

        $translated = $this->repository->translatedInFile($type, $domain, $locale);
        if ($translated === null) {
            $translated = $this->countTranslatedInFile($type, $domain, $locale);
            $this->repository->setTranslatedInFile($type, $domain, $locale, $translated);
        }

        return ['total' => $total, 'translated' => min($translated, $total)];
    }

    public function recountTranslatedInFile(string $type, string $domain, string $locale): void
    {
        $this->repository->setTranslatedInFile(
            $type,
            $domain,
            $locale,
            $this->countTranslatedInFile($type, $domain, $locale)
        );
    }

    public function refresh(string $path, string $type, string $domain): ?array
    {
        $scanned = $this->scanner->scan($path, $domain);
        if ($scanned === null) {
            return null;
        }

        $before = $this->repository->countActive($type, $domain);

        $this->repository->bulkUpsert($type, $domain, $scanned);

        $seenHashes = array_map(
            fn (array $entry): string => $this->repository->hashHex($entry['msgid'], $entry['context']),
            $scanned
        );
        $removed = $this->repository->deleteMissing($type, $domain, $seenHashes);
        $this->repository->markScanned($type, $domain);

        $after = $this->repository->countActive($type, $domain);

        return [
            'total'   => $after,
            'added'   => max(0, $after - $before + $removed),
            'removed' => $removed,
        ];
    }

    public function domainsMatching(string $search): array
    {
        return $this->repository->domainsMatching($search);
    }

    public function scannedGroupKeys(): array
    {
        return $this->repository->scannedGroupKeys();
    }

    public function ignoreUnit(string $type, string $domain, string $locale, string $msgid, ?string $context): void
    {
        $this->repository->ignore($type, $domain, $msgid, $context);
    }

    public function saveUnit(string $type, string $domain, string $locale, string $msgid, ?string $context, string $msgstr): bool
    {
        if (! class_exists('MO')) {
            require_once ABSPATH . WPINC . '/pomo/mo.php';
        }

        $target = $this->paths->targetPath($type, $domain, $locale);

        $mo = new \MO();
        if (file_exists($target)) {
            $mo->import_from_file($target);
        } else {
            $mo->headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $mo->headers['Language']     = $locale;
        }

        $mo->add_entry(new \Translation_Entry([
            'singular'     => $msgid,
            'context'      => $context,
            'translations' => [$msgstr],
        ]));

        wp_mkdir_p(dirname($target));
        $staging = wp_tempnam(basename($target));
        if (! $mo->export_to_file($staging)) {
            @unlink($staging);
            return false;
        }

        if (! rename($staging, $target)) {
            return false;
        }

        $this->repository->setTranslatedInFile($type, $domain, $locale, $this->countTranslatedInMo($mo));

        return true;
    }

    private function ensureScanned(string $path, string $type, string $domain): bool
    {
        if ($this->repository->wasScanned($type, $domain)) {
            return true;
        }

        $scanned = $this->scanner->scan($path, $domain);
        if ($scanned === null) {
            return false;
        }

        $this->repository->bulkUpsert($type, $domain, $scanned);
        $this->repository->markScanned($type, $domain);

        return true;
    }

    private function readExisting(string $type, string $domain, string $locale): array
    {
        $target = $this->paths->targetPath($type, $domain, $locale);
        if (! file_exists($target)) {
            return [];
        }

        if (! class_exists('MO')) {
            require_once ABSPATH . WPINC . '/pomo/mo.php';
        }

        $mo = new \MO();
        $mo->import_from_file($target);

        $out = [];
        foreach ($mo->entries as $key => $entry) {
            $out[$key] = (string) ($entry->translations[0] ?? '');
        }

        return $out;
    }

    private function countTranslatedInFile(string $type, string $domain, string $locale): int
    {
        $target = $this->paths->targetPath($type, $domain, $locale);
        if (! file_exists($target)) {
            return 0;
        }

        if (! class_exists('MO')) {
            require_once ABSPATH . WPINC . '/pomo/mo.php';
        }

        $mo = new \MO();
        $mo->import_from_file($target);

        return $this->countTranslatedInMo($mo);
    }

    private function countTranslatedInMo(\MO $mo): int
    {
        $n = 0;
        foreach ($mo->entries as $entry) {
            if ((string) ($entry->translations[0] ?? '') !== '') {
                $n++;
            }
        }

        return $n;
    }

    private function moKey(string $msgid, ?string $context): string
    {
        return $context !== null && $context !== '' ? $context . "\x04" . $msgid : $msgid;
    }
}

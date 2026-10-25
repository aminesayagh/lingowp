<?php

namespace LingoWP\GettextDomains;

/**
 * The editable "translate this plugin/theme in-app" surface: the full list
 * of a component's strings, merged with whatever translations already exist
 * in its `.mo` — however that `.mo` got there (a wordpress.org language pack,
 * an uploaded `.po`, or a previous manual save here). One save writes one
 * entry back into that same `.mo`, same file WordPress actually serves —
 * there is no separate draft/staging store; the file itself is the
 * persistence, same as every other file-based piece of this feature.
 *
 * Original strings + ignore status live in GettextUnitRepository (a DB
 * table), populated once per component via GettextTemplateGenerator's source
 * scan (ensureScanned()) instead of re-scanning on every read. Translated
 * text (msgstr) is never stored there — it stays in the `.mo` file, read
 * fresh via readExisting() same as always.
 */
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

    /**
     * @param 'plugin'|'theme' $type
     * @param string $search when non-empty, only strings whose msgid contains it
     * @return array<int, array{msgid: string, context: ?string, msgstr: string}>|null
     *         Null if there's no source directory to scan (e.g. a single-file plugin).
     */
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

    /**
     * Scan one component's source into the DB if it hasn't been yet — the
     * public entry point for GettextBoard.jsx's progressive "scan every
     * plugin/theme so the header search can match their original text" pass.
     * Idempotent (no-ops when already scanned); false when there's no source
     * directory (a single-file plugin).
     *
     * @param 'plugin'|'theme' $type
     */
    public function ensureComponentScanned(string $path, string $type, string $domain): bool
    {
        return $this->ensureScanned($path, $type, $domain);
    }

    /**
     * Whether a component has already been scanned, with no side effect —
     * lets a caller (the board-wide "Translate All" scan budget) tell "cheap,
     * already indexed" apart from "about to pay a real AST scan" before
     * deciding whether to spend this request's scan budget on it.
     *
     * @param 'plugin'|'theme' $type
     */
    public function wasComponentScanned(string $type, string $domain): bool
    {
        return $this->repository->wasScanned($type, $domain);
    }

    /**
     * Domain-wide translated/total ratio for the board's percent badge
     * (GettextDomainRestController::percent()).
     *
     * `total` is countActive() — one indexed COUNT. `translated` is a plain
     * int this plugin recorded the last time it wrote the `.mo`
     * (GettextUnitRepository::translatedInFile()); the only time this parses
     * the file is the first read for a domain+locale it has never recorded,
     * after which every write keeps the stored count current. It's a count
     * of non-empty entries in the `.mo`, not an intersection with the active
     * source set — a deliberately approximate number (the badge is a rough
     * progress hint, not an audit), clamped to `total` so a slightly stale
     * `.mo` can't show >100%.
     *
     * @param 'plugin'|'theme' $type
     * @return array{total: int, translated: int}|null null if no source to
     *         scan, or the component genuinely has zero strings.
     */
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

    /**
     * Re-read the `.mo` and store its translated-entry count. Called from
     * the controller after an import or an online-pack install replaces the
     * whole file — the one-string saveUnit() path updates the stored count
     * from its in-memory `\MO` directly instead.
     *
     * @param 'plugin'|'theme' $type
     */
    public function recountTranslatedInFile(string $type, string $domain, string $locale): void
    {
        $this->repository->setTranslatedInFile(
            $type,
            $domain,
            $locale,
            $this->countTranslatedInFile($type, $domain, $locale)
        );
    }

    /**
     * Re-scans a component's source, refreshing GettextUnitRepository's rows:
     * newly-found strings are added, still-present ones have last_seen_at
     * bumped, and anything no longer found is deleted outright (see
     * GettextUnitRepository::deleteMissing()'s doc for why hard-delete is the
     * right call here, unlike the HTML board's flag-and-review staleness).
     *
     * @param 'plugin'|'theme' $type
     * @return array{total: int, added: int, removed: int}|null null if no
     *         source directory to scan.
     */
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

    /** @return array<string, true> keyed by "type:domain" — see GettextUnitRepository::domainsMatching(). */
    public function domainsMatching(string $search): array
    {
        return $this->repository->domainsMatching($search);
    }

    /** @return string[] "type:domain" keys already scanned — see GettextUnitRepository::scannedGroupKeys(). */
    public function scannedGroupKeys(): array
    {
        return $this->repository->scannedGroupKeys();
    }

    /**
     * @param 'plugin'|'theme' $type
     */
    public function ignoreUnit(string $type, string $domain, string $locale, string $msgid, ?string $context): void
    {
        $this->repository->ignore($type, $domain, $msgid, $context);
    }

    /**
     * Read-modify-write one entry into the target `.mo`, creating it (with
     * sane default headers) on the first save for a domain+locale that has
     * no translation yet. Rename-into-place keeps the write atomic.
     *
     * @param 'plugin'|'theme' $type
     */
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
            wp_delete_file($staging);
            return false;
        }

        if (! rename($staging, $target)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap, so WordPress never loads a half-written .mo; the target is Lingostack's own WP_LANG_DIR/lingowp folder (see GettextPoImporter::baseDir()).
            return false;
        }

        // Keep the board's percent current without ever re-parsing the file
        // on a read — $mo is the just-written state, already in memory.
        $this->repository->setTranslatedInFile($type, $domain, $locale, $this->countTranslatedInMo($mo));

        return true;
    }

    /**
     * Ensures GettextUnitRepository has this component's strings before a
     * read. Gates on wasScanned() alone, NOT hasRows() — rows can exist
     * without a real scan ever having run (the one-time ignored-units
     * migration pre-seeds ignore rows for domains it has never scanned), so
     * "has rows" is not proof "has been scanned". wasScanned() also covers
     * the genuinely-empty-component case (scanned once, found zero strings)
     * so that one doesn't re-scan on every single request forever.
     *
     * @param 'plugin'|'theme' $type
     */
    private function ensureScanned(string $path, string $type, string $domain): bool
    {
        if ($this->repository->wasScanned($type, $domain)) {
            return true;
        }

        $scanned = $this->scanner->scan($path, $domain);
        if ($scanned === null) {
            return false;
        }

        // Zero strings is only remembered when the scanner proves it can find
        // strings at all; otherwise the next open scans again instead of the
        // component being cached as empty forever.
        if ($scanned === [] && ! $this->scanner->isWorking()) {
            return true;
        }

        $this->repository->bulkUpsert($type, $domain, $scanned);
        $this->repository->markScanned($type, $domain);

        return true;
    }

    /** @return array<string, string> Translation_Entry::key() => translated text */
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

    /**
     * Non-empty translated entries in a domain+locale's `.mo`, 0 if the file
     * doesn't exist yet. The one place translatedCount() is allowed to parse
     * the file — and only on the first read for a given domain+locale.
     *
     * @param 'plugin'|'theme' $type
     */
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

    /** Same key shape as WP core's Translation_Entry::key() — context + \x04 + msgid. */
    private function moKey(string $msgid, ?string $context): string
    {
        return $context !== null && $context !== '' ? $context . "\x04" . $msgid : $msgid;
    }
}

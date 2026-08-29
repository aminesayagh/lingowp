<?php

namespace LingoWP\GettextDomains;

/**
 * The editable "translate this plugin/theme in-app" surface: the full list
 * of a component's strings (via GettextTemplateGenerator's source scan),
 * merged with whatever translations already exist in its `.mo` — however
 * that `.mo` got there (a wordpress.org language pack, an uploaded `.po`, or
 * a previous manual save here). One save writes one entry back into that
 * same `.mo`, same file WordPress actually serves — there is no separate
 * draft/staging store; the file itself is the persistence, same as every
 * other file-based piece of this feature.
 */
final class GettextTranslationUnitsService
{
    private GettextTemplateGenerator $scanner;
    private GettextPoImporter $paths;
    private GettextIgnoredUnitsStore $ignored;

    public function __construct(
        GettextTemplateGenerator $scanner,
        GettextPoImporter $paths,
        GettextIgnoredUnitsStore $ignored
    ) {
        $this->scanner = $scanner;
        $this->paths   = $paths;
        $this->ignored = $ignored;
    }

    /**
     * @param 'plugin'|'theme' $type
     * @return array<int, array{msgid: string, context: ?string, msgstr: string}>|null
     *         Null if there's no source directory to scan (e.g. a single-file plugin).
     */
    public function list(string $path, string $type, string $domain, string $locale): ?array
    {
        $scanned = $this->scanner->scan($path, $domain);
        if ($scanned === null) {
            return null;
        }

        $existing = $this->readExisting($type, $domain, $locale);

        $units = [];
        foreach ($scanned as $entry) {
            if ($this->ignored->isIgnored($type, $domain, $locale, $entry['msgid'], $entry['context'])) {
                continue;
            }

            $key = $entry['context'] !== null && $entry['context'] !== ''
                ? $entry['context'] . "\x04" . $entry['msgid']
                : $entry['msgid'];

            $units[] = [
                'msgid'   => $entry['msgid'],
                'context' => $entry['context'],
                'msgstr'  => $existing[$key] ?? '',
            ];
        }

        return $units;
    }

    /**
     * @param 'plugin'|'theme' $type
     */
    public function ignoreUnit(string $type, string $domain, string $locale, string $msgid, ?string $context): void
    {
        $this->ignored->ignore($type, $domain, $locale, $msgid, $context);
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
            @unlink($staging);
            return false;
        }

        return rename($staging, $target);
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
}

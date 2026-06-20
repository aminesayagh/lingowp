<?php

namespace TransLangs\TranslationMemory\Infrastructure;

use TransLangs\Source\Domain\OptionSource;
use TransLangs\Source\Domain\SourceTranslationRepository;

/**
 * Options discovery (Path E): extract the small subset of visitor-facing text
 * stored in wp_options and upsert it into tl_option. Conservative by design —
 * an ALLOWLIST of option paths, never a blind sweep of every option — so plugin
 * configuration and serialized data are left untouched (see the wp_options
 * translation strategy spec). Discovery only; rendering is OptionResolver's job
 * and never mutates the stored option value.
 */
final class OptionSourceScanner
{
    /**
     * Allowlisted option paths to discover.
     *   key   = option_name
     *   value = list of dot-paths into the (maybe-serialized) value; '' means the
     *           scalar value itself. A path pointing at a container is walked
     *           recursively, one source per leaf string.
     * Extend via the `translangs_option_paths` filter (e.g. theme_mods/widgets).
     */
    private const ALLOWLIST = [
        'blogname'        => [''],
        'blogdescription' => [''],
    ];

    public function __construct(
        private SourceTranslationRepository $repository,
        private \wpdb $wpdb
    ) {}

    /** Discover allowlisted option text. Returns the number of new parent rows. */
    public function scan(): int
    {
        $records = [];

        foreach ($this->allowlist() as $optionName => $paths) {
            $optionId = $this->optionId((string) $optionName);
            if ($optionId === null) {
                continue;
            }

            $value = maybe_unserialize(get_option($optionName));

            foreach ((array) $paths as $path) {
                $node = $this->valueAtPath($value, (string) $path);
                foreach ($this->collectStrings($node, (string) $path) as [$leafPath, $text]) {
                    if (!$this->looksTranslatable($text)) {
                        continue;
                    }
                    $records[] = new OptionSource($optionId, (string) $optionName, $leafPath, $text);
                }
            }
        }

        return $records === [] ? 0 : $this->repository->upsertSources($records);
    }

    /** @return array<string, string[]> */
    private function allowlist(): array
    {
        $list = self::ALLOWLIST;

        // Customizer text (theme-specific option name) and legacy widget instances
        // are walked recursively ('' = every leaf string); looksTranslatable() drops
        // the technical/markup values that come with them.
        $list['theme_mods_' . get_stylesheet()] = [''];
        foreach ($this->widgetOptionNames() as $name) {
            $list[$name] = [''];
        }

        $list = apply_filters('translangs_option_paths', $list);

        return is_array($list) ? $list : self::ALLOWLIST;
    }

    /**
     * Legacy widget option names (widget_text, widget_nav_menu, …). Block widgets
     * (widget_block) carry block markup, dropped by looksTranslatable() and better
     * served by the type-5 HTML pipeline, so they need no special-casing here.
     *
     * @return string[]
     */
    private function widgetOptionNames(): array
    {
        $names = $this->wpdb->get_col( // phpcs:ignore WordPress.DB
            "SELECT option_name FROM {$this->wpdb->options} WHERE option_name LIKE 'widget\\_%'"
        );

        return is_array($names) ? array_map('strval', $names) : [];
    }

    private function optionId(string $optionName): ?int
    {
        $id = $this->wpdb->get_var( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "SELECT option_id FROM {$this->wpdb->options} WHERE option_name = %s",
                $optionName
            )
        );

        return ($id !== null && (int) $id > 0) ? (int) $id : null;
    }

    /** Descend a dot-path into an array/object value; null if the path is absent. */
    private function valueAtPath(mixed $value, string $path): mixed
    {
        if ($path === '') {
            return $value;
        }

        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } elseif (is_object($value) && isset($value->{$segment})) {
                $value = $value->{$segment};
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Recursively yield [path, string] for every scalar string under $node, the
     * path built from $prefix. Scalars at the root yield [$prefix, value].
     *
     * @return list<array{0:string,1:string}>
     */
    private function collectStrings(mixed $node, string $prefix): array
    {
        if (is_string($node)) {
            return [[$prefix, $node]];
        }

        $out = [];
        if (is_array($node) || is_object($node)) {
            foreach ((array) $node as $key => $child) {
                $childPath = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                array_push($out, ...$this->collectStrings($child, $childPath));
            }
        }

        return $out;
    }

    /**
     * Cheap "is this human-facing text?" guard. The allowlist already does the
     * heavy lifting; this just drops the obvious technical values (urls, emails,
     * hex colours, slugs, numbers) that can slip in from a serialized subtree.
     */
    private function looksTranslatable(string $text): bool
    {
        $t = trim($text);

        if ($t === '' || mb_strlen($t) < 2) {
            return false;
        }
        if (!preg_match('/\p{L}/u', $t)) {            // must contain a letter
            return false;
        }
        if (preg_match('/<[!\/a-z]/i', $t)) {         // HTML / block markup
            return false;
        }
        if ($t[0] === '/' || preg_match('#^https?://#i', $t)) { // path / url
            return false;
        }
        if (filter_var($t, FILTER_VALIDATE_EMAIL)) {  // email
            return false;
        }
        if (preg_match('/^#[0-9a-f]{3,8}$/i', $t)) {  // hex colour
            return false;
        }
        if (preg_match('/^\d+(\.\d+)?\s*(px|em|rem|%|pt|vh|vw)?$/i', $t)) { // number / css unit
            return false;
        }

        return true;
    }
}

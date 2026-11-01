<?php

namespace LingoWP\Extraction;

use LingoWP\Shared\Source\Type\OptionSource;
use LingoWP\Database\Repository\WpDbSourceRepository;

/**
 * Options discovery (Path E): extract the small subset of visitor-facing text
 * stored in wp_options and upsert it into option. Conservative by design —
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
     * Extend via the `lingowp_option_paths` filter (e.g. theme_mods/widgets).
     */
    private const ALLOWLIST = [
        'blogname'        => [''],
        'blogdescription' => [''],
    ];

    private WpDbSourceRepository $repository;
    private \wpdb $wpdb;

    public function __construct(
        WpDbSourceRepository $repository,
        \wpdb $wpdb
    ) {
        $this->repository = $repository;
        $this->wpdb       = $wpdb;
    }

    /**
     * Discover allowlisted option text, reconciling one wp_options row at a time
     * so an edited value carries its translation forward as needs_review (the
     * same rule posts and terms follow) instead of stranding it on the old text.
     *
     * Pass $onlyOption to re-discover a single option — what the branding save
     * hooks do, so saving the tagline doesn't unserialize and walk every widget
     * and theme mod. (The allowlist itself is still assembled either way; that
     * costs one option_name query.)
     */
    public function scan(?string $onlyOption = null): void
    {
        // A network site being created writes its default options before
        // Linvara's tables exist there (Activator::initializeSite() runs after);
        // scanning then logged "table doesn't exist". Its own activation scans.
        if (! \LingoWP\Database\TranslationMemorySchema::isInstalled()) {
            return;
        }

        foreach ($this->allowlist() as $optionName => $paths) {
            $optionName = (string) $optionName;
            if ($onlyOption !== null && $optionName !== $onlyOption) {
                continue;
            }

            $optionId = $this->optionId($optionName);
            if ($optionId === null) {
                continue; // no such option row; FK cascade already removed any sources
            }

            $value   = maybe_unserialize(get_option($optionName));

            // WordPress mirrors sidebars_widgets into theme_mods (retrieve_widgets()).
            // Its leaves are widget IDs and sidebar-assignment bookkeeping
            // ("block-2", "wp_inactive_widgets", …), never translatable text —
            // strip it before the recursive walk so those slugs don't pass
            // looksTranslatable() and land in the Site settings collection.
            if ($optionName === 'theme_mods_' . get_stylesheet() && is_array($value)) {
                unset($value['sidebars_widgets']);
            }

            $records = [];

            foreach ((array) $paths as $path) {
                $node = $this->valueAtPath($value, (string) $path);
                foreach ($this->collectStrings($node, (string) $path) as [$leafPath, $text]) {
                    if (!$this->looksTranslatable($text)) {
                        continue;
                    }
                    $records[] = new OptionSource($optionId, $optionName, $leafPath, $text);
                }
            }

            // Reconcile even when $records is empty: text removed from the option
            // must prune its sources, which is exactly what an empty scope does.
            $this->repository->reconcileOption($optionId, $records);
        }
    }

    /**
     * Cheap check for the dynamically-discovered half of the allow-list (theme
     * mods + classic widgets) — the half with no single fixed option name to
     * hook directly, unlike blogname/blogdescription. Used by the live
     * added_option/updated_option listener to skip the vast majority of
     * irrelevant option writes (transients, cron locks, …) without paying for
     * scan()'s full allowlist() assembly (a DB query) on every single one.
     */
    public function isDynamicallyAllowlisted(string $optionName): bool
    {
        return $optionName === 'theme_mods_' . get_stylesheet()
            || str_starts_with($optionName, 'widget_');
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

        $list = apply_filters('lingowp_option_paths', $list);

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
        // phpcs:disable PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names only, from $wpdb and the plugin's schema map; no values in the SQL.
        $names = $this->wpdb->get_col( // nosemgrep: wpdb-interpolated-sql -- table name only, from the TranslationMemorySchema map / $wpdb prefix; no values interpolated
            "SELECT option_name FROM {$this->wpdb->options} WHERE option_name LIKE 'widget\\_%'"
        );
        // phpcs:enable

        return is_array($names) ? array_map('strval', $names) : [];
    }

    private function optionId(string $optionName): ?int
    {
        // phpcs:disable PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
        $id = $this->wpdb->get_var( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "SELECT option_id FROM {$this->wpdb->options} WHERE option_name = %s",
                $optionName
            )
        );
        // phpcs:enable

        return ($id !== null && (int) $id > 0) ? (int) $id : null;
    }

    /** Descend a dot-path into an array/object value; null if the path is absent. */
    private function valueAtPath($value, string $path)
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
    private function collectStrings($node, string $prefix): array
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

<?php

namespace LingoWP\LocalizationRouting;

use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Language\Domain\LanguageRegistry;
use LingoWP\Shared\Source\ScannablePostStatuses;
use LingoWP\Shared\Text\TranslationKey;

final class SlugMap
{
    public const OPTION_ENABLED = 'lingowp_translate_slugs';

    private const OPTION_VERSION = 'lingowp_slug_map_version';

    private const TTL = WEEK_IN_SECONDS;

    private \wpdb $wpdb;
    private LanguageRegistry $registry;

    private array $perRequest = [];

    private array $spanCache = [];

    private array $realSlugsCache = [];

    public function __construct(\wpdb $wpdb, LanguageRegistry $registry)
    {
        $this->wpdb     = $wpdb;
        $this->registry = $registry;
    }

    public function register(): void
    {
        foreach (
            [
                'wp_after_insert_post',
                'add_attachment',
                'edit_attachment',
                'deleted_post',
                'created_term',
                'edited_term',
                'delete_term',
                'lingowp_language_roster_changed',
            ] as $hook
        ) {
            add_action($hook, [self::class, 'bumpVersion']);
        }

        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [self::class, 'bumpForOldSlugMeta'], 10, 3);
        }

        add_action('update_option_' . self::OPTION_ENABLED, [self::class, 'bumpVersion']);
        add_action('add_option_' . self::OPTION_ENABLED, [self::class, 'bumpVersion']);
    }

    public static function bumpVersion(): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        $current = (int) get_option(self::OPTION_VERSION, '0');
        update_option(self::OPTION_VERSION, (string) ($current + 1), false);
    }

    public static function bumpForOldSlugMeta($metaId, $objectId, $metaKey): void
    {
        unset($metaId, $objectId);

        if ($metaKey === '_wp_old_slug') {
            self::bumpVersion();
        }
    }

    public static function version(): string
    {
        return function_exists('get_option') ? (string) get_option(self::OPTION_VERSION, '0') : '0';
    }

    public function localize(string $path, string $lang): string
    {
        if (!$this->mappablePath($path) || !$this->shouldMap($lang)) {
            return $path;
        }

        return $this->mapSlugSegments($path, $this->maps($lang)['forward']);
    }

    public function toSource(string $path, string $lang): string
    {
        if (!$this->mappablePath($path) || !$this->shouldMap($lang)) {
            return $path;
        }

        return $this->mapSlugSegments($path, $this->maps($lang)['reverse']);
    }

    private function mappablePath(string $path): bool
    {
        return $path !== '' && $path !== '/';
    }

    private function shouldMap(string $lang): bool
    {
        return $lang !== ''
            && did_action('wp_loaded')
            && (bool) get_option(self::OPTION_ENABLED, false)
            && $lang !== $this->registry->getDefaultLanguage()
            && $this->registry->isEnabledLanguage($lang);
    }

    private function mapSlugSegments(string $path, array $map): string
    {
        if ($map === [] || $path === '' || $path === '/') {
            return $path;
        }

        if (!self::hasMappableSegment($path, $map)) {
            return $path;
        }

        $spans = $this->slugSpans($path);
        if ($spans === []) {
            return $path;
        }

        foreach ($spans as [$offset, $length]) {
            $mapped = self::mapSegments(substr($path, $offset, $length), $map);
            $path   = substr_replace($path, $mapped, $offset, $length);
        }

        return $path;
    }

    public static function mapSegments(string $fragment, array $map): string
    {
        if ($map === [] || $fragment === '') {
            return $fragment;
        }

        $segments = explode('/', $fragment);
        $changed  = false;

        foreach ($segments as $i => $segment) {
            $decoded = rawurldecode($segment);
            if (isset($map[$decoded])) {
                $segments[$i] = $map[$decoded];
                $changed      = true;
            }
        }

        return $changed ? implode('/', $segments) : $fragment;
    }

    private static function hasMappableSegment(string $path, array $map): bool
    {
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment !== '' && isset($map[rawurldecode($segment)])) {
                return true;
            }
        }

        return false;
    }

    private function slugSpans(string $path): array
    {
        if (isset($this->spanCache[$path])) {
            return $this->spanCache[$path];
        }

        global $wp_rewrite;

        $rules = $wp_rewrite instanceof \WP_Rewrite ? (array) $wp_rewrite->wp_rewrite_rules() : [];

        return $this->spanCache[$path] = RouteSlugSpans::find($path, $rules, $this->slugVars());
    }

    private function slugVars(): array
    {
        $vars = array_fill_keys(RouteSlugSpans::CORE_SLUG_VARS, true);

        foreach ([get_post_types([], 'objects'), get_taxonomies([], 'objects')] as $objects) {
            foreach ($objects as $object) {
                $queryVar = $object->query_var ?? '';
                if (is_string($queryVar) && $queryVar !== '') {
                    $vars[$queryVar] = true;
                }
            }
        }

        return $vars;
    }

    private function maps(string $lang): array
    {
        $version = (string) get_option(self::OPTION_VERSION, '0');

        $memoKey = $lang . '|' . $version;
        if (isset($this->perRequest[$memoKey])) {
            return $this->perRequest[$memoKey];
        }

        $key    = 'lingowp_slug_map_' . sanitize_key($lang) . '_' . $version;
        $cached = get_transient($key);

        if (is_array($cached) && isset($cached['forward'], $cached['reverse'])) {
            return $this->perRequest[$memoKey] = $cached;
        }

        $maps = self::build($this->loadRows($lang), $this->realSlugs($version));
        set_transient($key, $maps, self::TTL);

        return $this->perRequest[$memoKey] = $maps;
    }

    public static function build(array $rows, array $realSlugs): array
    {
        $forward = [];
        $reverse = [];

        foreach ($rows as $row) {
            $srcRaw = (string) $row['src'];
            $dstRaw = (string) ($row['dst'] ?? '');

            if ($srcRaw === '' || $dstRaw === '') {
                continue;
            }

            $srcKey = rawurldecode($srcRaw);
            $dstKey = rawurldecode($dstRaw);

            if (isset($realSlugs[$dstKey])) {
                continue;
            }

            if (isset($forward[$srcKey])) {
                continue;
            }

            if (isset($reverse[$dstKey])) {
                continue;
            }

            $forward[$srcKey] = $dstRaw;
            $reverse[$dstKey] = $srcRaw;
        }

        return ['forward' => $forward, 'reverse' => $reverse];
    }

    private function loadRows(string $lang): array
    {
        $parents = TranslationMemorySchema::parentTables();
        $rows    = [];

        $postTypes = array_values(get_post_types(['public' => true], 'names'));
        if ($postTypes !== []) {
            $postParent = $parents['post'];
            $postChild  = $postParent . '_translation';
            $statuses   = ScannablePostStatuses::ALL;

            $sql = "SELECT wp.ID AS owner_id, wp.post_name AS src, wp.post_title AS owner_text,
                           p.original_text AS original_text, c.translated_text AS dst
                    FROM {$this->wpdb->posts} wp
                    LEFT JOIN {$postParent} p
                           ON p.post_id = wp.ID AND p.field = 'post_title' AND p.status = 0
                    LEFT JOIN {$postChild} c
                           ON c.original_hash = p.original_hash AND c.lang_code = %s
                    WHERE wp.post_name <> ''
                      AND wp.post_status IN (" . $this->placeholders($statuses) . ")
                      AND wp.post_type IN (" . $this->placeholders($postTypes) . ')
                    ORDER BY wp.ID';

            $args = array_merge([$lang], $statuses, $postTypes);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $postRows = (array) $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A); // nosemgrep: wpdb-interpolated-sql -- table names from TranslationMemorySchema::parentTables()/$wpdb; every value is a placeholder

            $rows = $this->preferCurrentSource($postRows, 'post_title');
        }

        $taxonomies = array_values(get_taxonomies(['public' => true], 'names'));
        if ($taxonomies !== []) {
            $termParent = $parents['term'];
            $termChild  = $termParent . '_translation';

            $sql = "SELECT t.term_id AS owner_id, t.slug AS src, t.name AS owner_text,
                           p.original_text AS original_text, c.translated_text AS dst
                    FROM {$this->wpdb->terms} t
                    INNER JOIN {$this->wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                    LEFT JOIN {$termParent} p
                           ON p.term_taxonomy_id = tt.term_taxonomy_id AND p.field = 'name' AND p.status = 0
                    LEFT JOIN {$termChild} c
                           ON c.original_hash = p.original_hash AND c.lang_code = %s
                    WHERE t.slug <> ''
                      AND tt.taxonomy IN (" . $this->placeholders($taxonomies) . ')
                    ORDER BY t.term_id';

            $args = array_merge([$lang], $taxonomies);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $termRows = (array) $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A); // nosemgrep: wpdb-interpolated-sql -- table names from TranslationMemorySchema::parentTables()/$wpdb; every value is a placeholder

            $rows = array_merge($rows, $this->preferCurrentSource($termRows, 'name'));
        }

        foreach ($rows as $i => $row) {
            $dst = isset($row['dst']) && (string) $row['dst'] !== ''
                ? sanitize_title((string) $row['dst'])
                : null;

            $rows[$i] = [
                'owner_id' => $row['owner_id'],
                'src'      => sanitize_title((string) $row['src']),
                'dst'      => $dst === '' ? null : $dst,
            ];
        }

        return $rows;
    }

    private function preferCurrentSource(array $rows, string $field): array
    {
        $byOwner = [];
        foreach ($rows as $row) {
            $byOwner[(string) ($row['owner_id'] ?? '')][] = $row;
        }

        $out = [];
        foreach ($byOwner as $group) {
            $current = [];
            foreach ($group as $row) {
                if ($row['dst'] === null || $this->isCurrentSource($row, $field)) {
                    $current[] = $row;
                }
            }

            foreach ($current as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function isCurrentSource(array $row, string $field): bool
    {
        $ownerText = (string) ($row['owner_text'] ?? '');

        if ($field === 'post_title') {
            $ownerText = wptexturize($ownerText);
        }

        return TranslationKey::normalize($ownerText)
            === TranslationKey::normalize((string) ($row['original_text'] ?? ''));
    }

    private function realSlugs(string $version): array
    {
        if (isset($this->realSlugsCache[$version])) {
            return $this->realSlugsCache[$version];
        }

        $types = array_values(array_unique(array_merge(
            array_values(get_post_types(['public' => true], 'names')),
            array_values(get_post_types(['publicly_queryable' => true], 'names'))
        )));

        $slugs = [];

        if ($types !== []) {
            $sql = "SELECT post_name FROM {$this->wpdb->posts}
                     WHERE post_name <> ''
                       AND post_status NOT IN ('auto-draft', 'trash')
                       AND post_type IN (" . $this->placeholders($types) . ')';

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            foreach ((array) $this->wpdb->get_col($this->wpdb->prepare($sql, ...$types)) as $slug) { // nosemgrep: wpdb-interpolated-sql -- $wpdb table name; every value is a placeholder
                $slugs[rawurldecode(sanitize_title((string) $slug))] = true;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $oldSlugs = (array) $this->wpdb->get_col("SELECT meta_value FROM {$this->wpdb->postmeta} WHERE meta_key = '_wp_old_slug'"); // nosemgrep: wpdb-interpolated-sql -- the only interpolation is $wpdb->postmeta, a WordPress-provided table name; the meta key is a literal and no value is interpolated
        foreach ($oldSlugs as $slug) {
            if ((string) $slug !== '') {
                $slugs[rawurldecode(sanitize_title((string) $slug))] = true;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        foreach ((array) $this->wpdb->get_col("SELECT slug FROM {$this->wpdb->terms} WHERE slug <> ''") as $slug) { // nosemgrep: wpdb-interpolated-sql -- $wpdb table name only
            $slugs[rawurldecode(sanitize_title((string) $slug))] = true;
        }

        foreach ($this->registry->getEnabledLanguages() as $code) {
            $prefix = $this->registry->slugForLanguage($code);
            if ($prefix !== '') {
                $slugs[rawurldecode($prefix)] = true;
            }
        }

        return $this->realSlugsCache[$version] = $slugs;
    }

    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '%s'));
    }
}

<?php

namespace LingoWP\LocalizationRouting;

use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Language\Domain\LanguageRegistry;
use LingoWP\Shared\Source\ScannablePostStatuses;
use LingoWP\Shared\Text\TranslationKey;

/**
 * Translates URL path segments, so a page reachable at /about/team/ in the
 * source language is reachable at /fr/a-propos/equipe/ in French.
 *
 * The translated slug is DERIVED from the post title's (or term name's)
 * existing translation — sanitize_title() of it — so this feature adds no
 * source rows, no board rows and no AI cost: it re-reads translations the site
 * already has. A post with no translation keeps its source slug, which means a
 * language mid-translation serves prefixed source-language URLs that already
 * resolve. The site is never half-broken.
 *
 * The source slug ALWAYS keeps working as an alias: this maps segments, it
 * never unmaps them, so /fr/about/ and /fr/a-propos/ both render and every URL
 * published before the feature was switched on stays valid. hreflang and
 * rel=canonical point at the translated one (HreflangPresenter), so the
 * duplicate costs nothing in search.
 *
 * Both directions live here and are reached through LocalizedUrlBuilder:
 * stripPrefix() normalizes a path back to source slugs, addPrefix() localizes
 * it. Everything that builds a public URL already funnels through those two
 * (the <a href> buffer pass, redirects, hreflang/canonical, the switcher), and
 * LanguageRouteRegistrar normalizes the incoming path with toSource() before
 * WordPress matches a rewrite rule.
 *
 * PUBLISHED LANGUAGES ONLY. A draft language is absent from the registry's
 * enabled roster, so shouldMap() refuses it — a never-published language can
 * never have a public URL minted for it. Previewing a draft language
 * (?lingowp_preview=fr) rides an unprefixed source-slug path and is left alone
 * on purpose: nothing about it is public.
 *
 * A derived slug is used only when it is UNAMBIGUOUS and only when the segment
 * it would replace is an object slug at all — see build() for the full rule
 * set, every clause of which exists because a reproduction test showed the URL
 * resolving to the wrong page without it.
 */
final class SlugMap
{
    /** Opt-in: this rewrites every URL on the site, so it is off until asked for. */
    public const OPTION_ENABLED = 'lingowp_translate_slugs';

    private const OPTION_VERSION = 'lingowp_slug_map_version';

    /**
     * Cache lifetime. Long on purpose: every input change bumps the version
     * (see register() and WpDbSourceRepository::bumpSlugMapForHash()), so the
     * TTL is a backstop against a missed invalidation rather than the primary
     * freshness mechanism. It used to be ten minutes, which meant a site-wide
     * rebuild six times an hour for a map that usually never changes —
     * hreflang asks for EVERY enabled language's map on EVERY page, so a cheap
     * rebuild is not a thing that exists; a rare one is.
     */
    private const TTL = WEEK_IN_SECONDS;

    private \wpdb $wpdb;
    private LanguageRegistry $registry;

    /** @var array<string, array{forward: array<string,string>, reverse: array<string,string>}> */
    private array $perRequest = [];

    /** @var array<string, array<int, array{0:int,1:int}>> */
    private array $spanCache = [];

    /** @var array<string, array<string, true>> reserved slugs, keyed by cache generation */
    private array $realSlugsCache = [];


    public function __construct(\wpdb $wpdb, LanguageRegistry $registry)
    {
        $this->wpdb     = $wpdb;
        $this->registry = $registry;
    }

    /**
     * Invalidate when the set of real slugs changes, or when a language is
     * published or unpublished (publishing is what makes its URLs start
     * existing at all).
     *
     * Registered unconditionally, NOT gated on OPTION_ENABLED. A gate there
     * looks like a saving and is actually a correctness hole: with the feature
     * briefly off, nothing notices a new page claiming a slug the cached map
     * still hands to someone else, and re-enabling resurrects that map for the
     * rest of its TTL. Tracking costs one option write per post save.
     *
     * wp_after_insert_post rather than save_post, plus the two attachment
     * hooks, mirrors SourceLifecycleRefresher::register() — core returns before
     * save_post for attachments, and attachments are in this map.
     */
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

        // Renaming a post leaves a _wp_old_slug record so the old URL keeps
        // working, and realSlugs() reserves those. An importer writing one
        // directly fires none of the post hooks above, so the cached map would
        // keep handing that alias to whichever page derived it — the one
        // staleness in this class that serves the WRONG page, not just an old
        // URL. Hooked on the meta itself.
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [self::class, 'bumpForOldSlugMeta'], 10, 3);
        }

        // Turning the feature on must not reuse a map built before it was
        // turned off: a real slug could have appeared in between.
        add_action('update_option_' . self::OPTION_ENABLED, [self::class, 'bumpVersion']);
        add_action('add_option_' . self::OPTION_ENABLED, [self::class, 'bumpVersion']);
    }

    /**
     * Static so a write path can invalidate without holding an instance.
     *
     * A counter, not a timestamp: two slug-affecting changes inside the same
     * second must not produce the same cache key, or the second one's rebuild
     * is skipped and a stale map is served until the TTL expires.
     */
    public static function bumpVersion(): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        $current = (int) get_option(self::OPTION_VERSION, '0');
        update_option(self::OPTION_VERSION, (string) ($current + 1), false);
    }

    /**
     * @param mixed $metaId
     * @param mixed $objectId
     * @param mixed $metaKey
     */
    public static function bumpForOldSlugMeta($metaId, $objectId, $metaKey): void
    {
        unset($metaId, $objectId);

        if ($metaKey === '_wp_old_slug') {
            self::bumpVersion();
        }
    }

    /** The cache generation, for anything that caches a value derived from this map. */
    public static function version(): string
    {
        return function_exists('get_option') ? (string) get_option(self::OPTION_VERSION, '0') : '0';
    }

    /** "/about/team/" => "/a-propos/equipe/" */
    public function localize(string $path, string $lang): string
    {
        if (!$this->mappablePath($path) || !$this->shouldMap($lang)) {
            return $path;
        }

        return $this->mapSlugSegments($path, $this->maps($lang)['forward']);
    }

    /** "/a-propos/equipe/" => "/about/team/" */
    public function toSource(string $path, string $lang): string
    {
        if (!$this->mappablePath($path) || !$this->shouldMap($lang)) {
            return $path;
        }

        return $this->mapSlugSegments($path, $this->maps($lang)['reverse']);
    }

    /**
     * Nothing to map, so nothing to load. Checked before shouldMap() because
     * PHP evaluates maps($lang) as an argument to mapSegments() — the root path
     * used to build the whole map only to hand it a path with no segments.
     */
    private function mappablePath(string $path): bool
    {
        return $path !== '' && $path !== '/';
    }

    /**
     * The default language is excluded because its slugs ARE the source slugs:
     * there is nothing to map, and mapping would be the bug.
     */
    private function shouldMap(string $lang): bool
    {
        // wp_loaded, not init: did_action() counts a hook as fired as soon as
        // it STARTS (plugin.php increments before dispatching), so inside an
        // early init callback did_action('init') is already true while post
        // types and taxonomies registered on a later callback do not exist yet.
        // A map built then is missing real slugs, and would be cached that way.
        return $lang !== ''
            && did_action('wp_loaded')
            && (bool) get_option(self::OPTION_ENABLED, false)
            && $lang !== $this->registry->getDefaultLanguage()
            && $this->registry->isEnabledLanguage($lang);
    }

    /**
     * Rewrite only the segments the ROUTE says are object slugs.
     *
     * RouteSlugSpans explains why spelling alone is not enough; the short
     * version is that /author/jean/ and /about/jean/ are indistinguishable to a
     * per-segment map, and rewriting the first serves another author's archive.
     *
     * Substituting by byte span rather than str_replace() also confines the
     * rewrite to the path: a value that happens to repeat inside a query string
     * is no longer collateral.
     *
     * @param array<string, string> $map
     */
    private function mapSlugSegments(string $path, array $map): string
    {
        if ($map === [] || $path === '' || $path === '/') {
            return $path;
        }

        // Cheap gate before the rule walk: on a page full of links, most paths
        // contain nothing this map could touch.
        if (!self::hasMappableSegment($path, $map)) {
            return $path;
        }

        $spans = $this->slugSpans($path);
        if ($spans === []) {
            return $path;
        }

        // Spans arrive last-first, so earlier offsets stay valid as we go.
        foreach ($spans as [$offset, $length]) {
            $mapped = self::mapSegments(substr($path, $offset, $length), $map);
            $path   = substr_replace($path, $mapped, $offset, $length);
        }

        return $path;
    }

    /**
     * Replace whole segments of a slug-bearing path FRAGMENT, exact hits only.
     * A segment the map doesn't know is left exactly as it was, so an unmapped
     * path still resolves (source slugs always do).
     *
     * The fragment can hold several segments: a hierarchical `pagename` capture
     * is "parent/child", and each part of it is a slug in its own right.
     *
     * Static and pure, like RouteSlugSpans::find(), so the pure test can drive
     * it directly.
     *
     * @param array<string, string> $map
     */
    public static function mapSegments(string $fragment, array $map): string
    {
        if ($map === [] || $fragment === '') {
            return $fragment;
        }

        $segments = explode('/', $fragment);
        $changed  = false;

        foreach ($segments as $i => $segment) {
            // Keys are decoded, values raw — see build(). A segment can arrive
            // either way (a URL path is encoded, a query var has already been
            // decoded by WordPress), so decode before looking up.
            $decoded = rawurldecode($segment);
            if (isset($map[$decoded])) {
                $segments[$i] = $map[$decoded];
                $changed      = true;
            }
        }

        return $changed ? implode('/', $segments) : $fragment;
    }

    /**
     * @param array<string, string> $map
     */
    private static function hasMappableSegment(string $path, array $map): bool
    {
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment !== '' && isset($map[rawurldecode($segment)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Slug spans for a path, memoized per request: the link rewriter asks for
     * the same handful of paths many times over on one page.
     *
     * @return array<int, array{0:int,1:int}>
     */
    private function slugSpans(string $path): array
    {
        if (isset($this->spanCache[$path])) {
            return $this->spanCache[$path];
        }

        global $wp_rewrite;

        $rules = $wp_rewrite instanceof \WP_Rewrite ? (array) $wp_rewrite->wp_rewrite_rules() : [];

        return $this->spanCache[$path] = RouteSlugSpans::find($path, $rules, $this->slugVars());
    }

    /**
     * Query vars whose value is an object slug: the route-independent core set,
     * plus whatever this site's registered types and taxonomies use. Read from
     * the registries so a CPT or taxonomy needs no entry here.
     *
     * Deliberately NOT memoized across the request: a type registered after the
     * first lookup would be invisible for the rest of it, and its translated
     * URLs would route to the raw translated slug. The cost is bounded anyway —
     * slugSpans() memoizes per path, so this runs once per distinct path, not
     * once per link.
     *
     * @return array<string, true>
     */
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

    /**
     * @return array{forward: array<string,string>, reverse: array<string,string>}
     */
    private function maps(string $lang): array
    {
        $version = (string) get_option(self::OPTION_VERSION, '0');

        // Memo keyed by version as well as language: a translation saved
        // mid-request bumps the version, and an instance that keyed on language
        // alone kept emitting the slug it had already cached.
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

    /**
     * Resolve the safety rules into the two lookup tables. Pure: no WordPress,
     * no database, so tests/slug_map_check.php can drive it with fixture rows.
     *
     * Rows arrive in PRIORITY ORDER (loadRows() sorts by owner id, posts before
     * terms), which makes every rule below "first claim wins" — deterministic,
     * and stable in the sense that matters: re-reviewing one page's title
     * translation can never move a different page's URL.
     *
     * One rule, three shapes: a derived slug is used only if it is unambiguous.
     *
     *   1. Same source slug, different translations — ALLOWED. WordPress slugs
     *      are unique only per type+parent, so /about/team/ and /products/team/
     *      both own the slug "team". The first claim wins the segment and both
     *      pages still get distinct, correctly-resolving paths, because mapping
     *      is per-segment and the parent segments disambiguate.
     *   2. Two source slugs claiming one translated slug — LOSER DROPPED.
     *      "about-us" and "concerning-us" both deriving "a-propos" would send
     *      both pages' links to /fr/a-propos/, leaving one unreachable and
     *      serving the other's content. The loser keeps its source slug.
     *   3. Derived slug equal to a REAL slug anywhere on the site — DROPPED.
     *      A page titled "a-propos" already owns /fr/a-propos/ by source-slug
     *      passthrough; minting that URL for a different page would serve the
     *      wrong page. $realSlugs is deliberately wider than the set of
     *      translatable objects: an old-slug alias or a private page is still
     *      reachable, so it still owns its URL.
     * A segment's ROLE is not this method's business: RouteSlugSpans decides
     * which positions are object slugs at all, so route bases, pagination,
     * feeds and author names never reach a map lookup and need no rule here.
     *
     * Every conflict resolves to "drop", so the reverse map is injective by
     * construction and needs no second pass.
     *
     * @param array<int, array{owner_id: int|string, src: string, dst: ?string}> $rows
     * @param array<string, true> $realSlugs Decoded slugs that already own a URL.
     * @return array{forward: array<string,string>, reverse: array<string,string>}
     */
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

            // Rule 3. Also covers the no-op case where a title translation
            // sanitizes back to the page's own slug.
            if (isset($realSlugs[$dstKey])) {
                continue;
            }

            // Rule 1 — this slug already has its translation.
            if (isset($forward[$srcKey])) {
                continue;
            }

            // Rule 2 — another slug got here first. $reverse doubles as the
            // claim set, since every claim writes exactly one entry into it.
            if (isset($reverse[$dstKey])) {
                continue;
            }

            $forward[$srcKey] = $dstRaw;
            $reverse[$dstKey] = $srcRaw;
        }

        return ['forward' => $forward, 'reverse' => $reverse];
    }

    /**
     * Mapping CANDIDATES: slugs whose owner is eligible for translation, with
     * their (nullable) title/name translation, posts then terms, each ordered by
     * owner id — the priority order build() relies on.
     *
     * ponytail: the whole map in one transient, rebuilt only on an actual
     * change (see TTL). Ceiling: one large array per language in memory on a
     * very large site. Upgrade path is to write each derived slug to
     * _lingowp_slug_<lang> meta on translation save and do indexed per-segment
     * lookups instead — which is also where an explicit per-slug override
     * would live.
     *
     * @return array<int, array{owner_id: int|string, src: string, dst: ?string}>
     */
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

            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
            $postRows = (array) $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A); // nosemgrep: wpdb-interpolated-sql -- table names from TranslationMemorySchema::parentTables()/$wpdb; every value is a placeholder
            // phpcs:enable

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

            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
            $termRows = (array) $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A); // nosemgrep: wpdb-interpolated-sql -- table names from TranslationMemorySchema::parentTables()/$wpdb; every value is a placeholder
            // phpcs:enable

            $rows = array_merge($rows, $this->preferCurrentSource($termRows, 'name'));
        }

        foreach ($rows as $i => $row) {
            // sanitize_title() is what turns translated TITLE TEXT into a slug,
            // and the same call normalizes the stored slug, so both sides of a
            // comparison are spelled the same way.
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

    /**
     * When one owner has several live source rows for the same field, keep the
     * one whose text is the owner's CURRENT title/name.
     *
     * Normally a source-text change deletes the stale parent, so there is
     * exactly one row. Mid-reconcile — or after an interrupted one — there can
     * be two, and the join reads them through uniq_source
     * (owner, field, original_hash), so the row order is ORIGINAL_HASH BYTE
     * ORDER. Without this step a stale title's translation can win the slug,
     * deterministically but arbitrarily, depending only on which text hashes
     * lower — and with a single stale row there is no ordering involved at all,
     * it simply wins.
     *
     * Rows with no translation (dst null) pass through untouched: they carry no
     * slug either way, and they are what puts an owner's real slug in the
     * reserved set.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
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

            // Every row checked, including a lone one: an interrupted reconcile
            // can leave a single STALE row behind, and taking it unquestioned
            // published a slug derived from the previous title. When nothing
            // matches the owner's current text, the owner contributes no
            // mapping at all and keeps its source slug until reconciliation
            // catches up — a stale URL is worse than an untranslated one.
            foreach ($current as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isCurrentSource(array $row, string $field): bool
    {
        $ownerText = (string) ($row['owner_text'] ?? '');

        // The scanner texturizes a post_title before hashing it, because that
        // is what a visitor sees; compare on the same footing or nothing ever
        // matches. A term name is stored as-is.
        if ($field === 'post_title') {
            $ownerText = wptexturize($ownerText);
        }

        return TranslationKey::normalize($ownerText)
            === TranslationKey::normalize((string) ($row['original_text'] ?? ''));
    }

    /**
     * Every slug that already owns a URL on this site — the rule 3 set.
     *
     * Deliberately WIDER than the mapping candidates above: owning a URL only
     * requires being reachable, not being translatable. A private page is
     * reachable by whoever may read it, and an old slug is reachable through
     * wp_old_slug_redirect() — which only runs on a 404, so a derived slug that
     * resolves instead of 404ing silently replaces that redirect with the wrong
     * post.
     *
     * Three site-wide queries, and nothing in here depends on the language —
     * so it is memoized per cache GENERATION rather than per language. Without
     * that, a five-language site ran these fifteen times per cold build to get
     * five identical answers. Keyed by generation, not just per instance, so a
     * mid-request invalidation is still seen.
     *
     * @return array<string, true>
     */
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

            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from $wpdb and the plugin's schema map; every value goes through a placeholder.
            foreach ((array) $this->wpdb->get_col($this->wpdb->prepare($sql, ...$types)) as $slug) { // nosemgrep: wpdb-interpolated-sql -- $wpdb table name; every value is a placeholder
            // phpcs:enable
                $slugs[rawurldecode(sanitize_title((string) $slug))] = true;
            }
        }

        // Renamed posts keep answering their old URLs through this meta.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        // phpcs:disable PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names only, from $wpdb and the plugin's schema map; no values in the SQL.
        $oldSlugs = (array) $this->wpdb->get_col("SELECT meta_value FROM {$this->wpdb->postmeta} WHERE meta_key = '_wp_old_slug'"); // nosemgrep: wpdb-interpolated-sql -- the only interpolation is $wpdb->postmeta, a WordPress-provided table name; the meta key is a literal and no value is interpolated
        // phpcs:enable
        foreach ($oldSlugs as $slug) {
            if ((string) $slug !== '') {
                $slugs[rawurldecode(sanitize_title((string) $slug))] = true;
            }
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL -- table names only, from $wpdb and the plugin's schema map; no values in the SQL.
        foreach ((array) $this->wpdb->get_col("SELECT slug FROM {$this->wpdb->terms} WHERE slug <> ''") as $slug) { // nosemgrep: wpdb-interpolated-sql -- $wpdb table name only
        // phpcs:enable
            $slugs[rawurldecode(sanitize_title((string) $slug))] = true;
        }

        // Every language prefix owns URLs too. RouteSlugSpans keeps an INCOMING
        // prefix safe (it is a literal in the cloned rules, never a capture),
        // but the OUTGOING direction localizes a bare path with no prefix in it
        // yet, so a page whose translation derived a language slug would still
        // emit /zz/zz/ — indistinguishable from that language's home.
        foreach ($this->registry->getEnabledLanguages() as $code) {
            $prefix = $this->registry->slugForLanguage($code);
            if ($prefix !== '') {
                $slugs[rawurldecode($prefix)] = true;
            }
        }

        return $this->realSlugsCache[$version] = $slugs;
    }

    /**
     * @param array<int, string> $values
     */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '%s'));
    }
}

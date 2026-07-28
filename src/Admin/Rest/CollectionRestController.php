<?php

namespace TransLangs\Admin\Rest;

use TransLangs\Core\Repository\SourceTranslationRepository;
use TransLangs\Core\Source\Value\SourceRef;
use TransLangs\Core\Source\Value\TranslationStatus;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Workspace content REST endpoints (namespace translangs/v1) — read-only board.
 *
 * Collections are DERIVED groups, not stored rows: "Site settings" is the whole
 * tl_option table; each content collection is one WP post's tl_post units. A
 * collection id is an opaque token this controller owns — `option:site` or
 * `content:{postId}` — and is deliberately distinct from a SourceRef ({kind}:{id},
 * which addresses a single text unit for writes). Status/counts derive from the
 * schema per design-ux-data-mapping.md §2; nothing new is stored.
 *
 * v1 covers post content + site settings. Term/meta/html/dependency collections
 * are added next (see plan-ux-implementation-dashboard.md, Phase A).
 */
final class CollectionRestController
{
    private const NS = 'translangs/v1';

    /** Board search minimum. Below this the term is ignored and the tree is normal. */
    private const SEARCH_MIN = 2;

    /** Accepted `status` filter values; anything else is a 400, not a silent no-op. */
    private const VALID_STATUSES = ['all', 'untranslated', 'needs_review', 'translated'];

    /** Accepted `source` board values: today's Content board, or Plugins & Themes (gettext). */
    private const VALID_SOURCES = ['content', 'plugins'];

    /** Default/maximum page size for `GET /collections`. */
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 100;

    public function __construct(
        private SourceTranslationRepository $repo,
        private OptionLanguageRegistry $registry
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/collections', ['methods' => 'GET', 'callback' => [$this, 'index']] + $guard);
        register_rest_route(
            self::NS,
            '/collections/text-order',
            ['methods' => 'GET', 'callback' => [$this, 'textOrder']] + $guard
        );
        register_rest_route(
            self::NS,
            '/collections/(?P<id>[^/]+)/texts',
            ['methods' => 'GET', 'callback' => [$this, 'texts']] + $guard
        );
        register_rest_route(
            self::NS,
            '/collections/(?P<id>[^/]+)/ignore',
            ['methods' => 'POST', 'callback' => [$this, 'ignoreAll']] + $guard
        );
        register_rest_route(
            self::NS,
            '/collections/(?P<id>[^/]+)/clear',
            ['methods' => 'POST', 'callback' => [$this, 'clearAll']] + $guard
        );
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /** Collection groups for the selected language: Site settings first, then content. */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $lang   = $this->resolveLang($request);
        $search = $this->resolveSearch($request);
        $status = $this->resolveStatus($request);
        $source = $this->resolveSource($request);

        if (! in_array($status, self::VALID_STATUSES, true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_STATUS'], 400);
        }
        if (! in_array($source, self::VALID_SOURCES, true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_SOURCE'], 400);
        }

        $offset = max(0, (int) $request->get_param('offset'));
        $limit  = min(self::MAX_LIMIT, max(1, (int) ($request->get_param('limit') ?: self::DEFAULT_LIMIT)));

        $collections = $this->buildCollections($lang, $search, $source);

        if ($status !== 'all') {
            $collections = array_values(array_filter($collections, static fn (array $c): bool => match ($status) {
                'untranslated' => $c['missing'] > 0,
                'needs_review' => $c['needs_review'] > 0,
                'translated'   => $c['translated'] > 0,
            }));
        }

        // Header progress badge ("X of Y translated") describes the whole matching
        // set, not just the page returned — sum over the full filtered array before
        // slicing it, so scrolling never makes the badge look like it's shrinking.
        $totalTexts      = array_sum(array_column($collections, 'total'));
        $totalTranslated = array_sum(array_column($collections, 'translated'));

        // Response (not DB) pagination: slice the assembled, already-filtered list.
        $total    = count($collections);
        $page     = array_slice($collections, $offset, $limit);
        $returned = count($page);
        $next     = $offset + $returned;

        return rest_ensure_response([
            'ok'               => true,
            'language'         => $lang,
            // Echoed so the client knows which term/status produced this page — a
            // search term below SEARCH_MIN comes back as '' and the tree is unfiltered.
            'search'           => $search,
            'status'           => $status,
            'source'           => $source,
            'collections'      => array_values($page),
            'total'            => $total,
            'total_texts'      => $totalTexts,
            'total_translated' => $totalTranslated,
            'offset'           => $offset,
            'limit'            => $limit,
            'returned'         => $returned,
            'next_offset'      => $next,
            'has_more'         => $next < $total,
        ]);
    }

    /**
     * Every collection group for a language + search term (Site settings first,
     * then content, then taxonomies), unpaged. The aggregate queries behind this
     * cost about the same regardless of how much of the result index() keeps, so
     * building the full filtered list once and slicing it is cheaper than trying
     * to paginate at the SQL layer.
     *
     * $source picks the board: 'content' (default) is today's Site settings /
     * Template content / posts / taxonomies tree. 'plugins' is the gettext board,
     * grouped by textdomain, with unmatched domains collapsed into one "Other" row.
     */
    private function buildCollections(string $lang, string $search, string $source = 'content'): array
    {
        if ($source === 'plugins') {
            return $this->buildPluginCollections($lang, $search);
        }

        $collections = [];

        // With a search active every count describes the MATCHING subset, and a
        // collection with no hits produces no row at all — that is how empty groups
        // drop out of the board without any filtering here.
        $option = $this->repo->optionCollectionSummary($lang, $search);
        if ($option['total'] > 0) {
            $collections[] = [
                'id'             => 'option:site',
                'label'          => __('Site settings', 'translangs'),
                'type'           => 'site_settings',
                'preview_url'    => admin_url('options-general.php'),
                'preview_target' => 'admin',
            ] + $option;
        }

        $html = $this->repo->htmlCollectionSummary($lang, $search);
        if ($html['total'] > 0) {
            $collections[] = [
                'id'             => 'html:all',
                'label'          => __('Template content', 'translangs'),
                'type'           => 'html',
                'preview_url'    => null,
                'preview_target' => 'frontend',
            ] + $html;
        }

        // A content collection is one WP post's tl_post fields + its tl_meta,
        // merged by post_id (preserving the post-type/title ordering of tl_post).
        $byPost = [];
        foreach ($this->repo->listPostCollections($lang, $search) as $c) {
            $byPost[$c['post_id']] = $c;
        }
        foreach ($this->repo->postMetaCounts($lang, $search) as $m) {
            $pid = $m['post_id'];
            if (! isset($byPost[$pid])) {
                $byPost[$pid] = $m;
                continue;
            }
            foreach (['total', 'translated', 'needs_review', 'missing'] as $k) {
                $byPost[$pid][$k] += $m[$k];
            }
        }
        $contentRows = [];
        foreach ($byPost as $pid => $c) {
            $pid       = (int) $pid;
            $isFront   = $this->isFrontPage($pid, (string) $c['post_type'], (string) get_post_field('post_name', $pid));
            $isMedia   = $c['post_type'] === 'attachment';
            $permalink = get_permalink($pid);

            if ($isFront) {
                $previewUrl    = $this->frontendPreviewUrl(home_url('/'), $lang);
                $previewTarget = 'frontend';
            } elseif ($isMedia) {
                $previewUrl    = admin_url('post.php?post=' . $pid . '&action=edit');
                $previewTarget = 'admin';
            } else {
                $previewUrl    = $permalink === false ? null : $this->frontendPreviewUrl($permalink, $lang);
                $previewTarget = 'frontend';
            }

            $row = [
                'id'             => 'content:' . $pid,
                'label'          => $c['post_title'] !== '' ? $c['post_title'] : __('(no title)', 'translangs'),
                'type'           => $isFront ? 'page' : $this->uxType($c['post_type']),
                'total'          => $c['total'],
                'translated'     => $c['translated'],
                'needs_review'   => $c['needs_review'],
                'missing'        => $c['missing'],
                'preview_url'    => $previewUrl,
                'preview_target' => $previewTarget,
            ];

            if ($isFront) {
                array_unshift($contentRows, $row);
            } else {
                $contentRows[] = $row;
            }
        }
        foreach ($contentRows as $row) {
            $collections[] = $row;
        }

        // Terms group by their taxonomy: one collection per taxonomy (all post
        // categories together, all product categories together, …), not one per
        // term. Counts are summed across the taxonomy's terms.
        $byTax = [];
        foreach ($this->repo->listTermCollections($lang, $search) as $t) {
            $tax = $t['taxonomy'];
            if (! isset($byTax[$tax])) {
                $byTax[$tax] = ['total' => 0, 'translated' => 0, 'needs_review' => 0, 'missing' => 0];
            }
            foreach (['total', 'translated', 'needs_review', 'missing'] as $k) {
                $byTax[$tax][$k] += $t[$k];
            }
        }
        foreach ($byTax as $tax => $c) {
            $collections[] = [
                'id'             => 'taxonomy:' . $tax,
                'label'          => $this->taxonomyLabel($tax),
                'type'           => 'taxonomy',
                'preview_url'    => admin_url('edit-tags.php?taxonomy=' . urlencode($tax)),
                'preview_target' => 'admin',
            ] + $c;
        }

        return $collections;
    }

    /**
     * The Plugins & Themes board: one collection per textdomain that resolves to
     * an installed plugin/theme, plus one "Other" collection merging every domain
     * that doesn't (WordPress core's `default` domain, or a since-removed plugin).
     */
    private function buildPluginCollections(string $lang, string $search): array
    {
        $collections = [];
        $resolved    = $this->resolveTextDomains();
        $other       = ['total' => 0, 'translated' => 0, 'needs_review' => 0, 'missing' => 0];

        foreach ($this->repo->staticTextDomainSummary($lang, $search) as $row) {
            $domain = $row['domain'];
            if (isset($resolved[$domain])) {
                $collections[] = [
                    'id'             => 'plugin:' . rawurlencode($domain),
                    'label'          => $resolved[$domain]['label'],
                    'type'           => $resolved[$domain]['kind'], // 'plugin' | 'theme'
                    'preview_url'    => null,
                    'preview_target' => null,
                ] + $row;
                continue;
            }
            foreach (['total', 'translated', 'needs_review', 'missing'] as $k) {
                $other[$k] += $row[$k];
            }
        }

        if ($other['total'] > 0) {
            $collections[] = [
                'id'             => 'plugin:__other__',
                'label'          => __('Other', 'translangs'),
                'type'           => 'other',
                'preview_url'    => null,
                'preview_target' => null,
            ] + $other;
        }

        return $collections;
    }

    /** Text units of one collection for the selected language. */
    public function texts(WP_REST_Request $request): WP_REST_Response
    {
        $lang   = $this->resolveLang($request);
        $search = $this->resolveSearch($request);
        $id     = urldecode((string) $request->get_param('id'));

        $texts = $this->collectionTexts($id, $lang, $search);
        if ($texts === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        return rest_ensure_response([
            'ok'       => true,
            'language' => $lang,
            'search'   => $search,
            'texts'    => $texts,
        ]);
    }

    /** Text units of one collection, or null if `$id` doesn't resolve to a known collection. */
    private function collectionTexts(string $id, string $lang, string $search): ?array
    {
        if ($id === 'option:site') {
            $optionPreview = ['preview_url' => admin_url('options-general.php'), 'preview_target' => 'admin'];
            return array_map(
                fn (array $r): array => $this->presentText('option', $r, (string) $r['option_name']) + $optionPreview,
                $this->repo->listOptionTexts($lang, $search)
            );
        }

        if ($id === 'html:all') {
            return array_map(
                fn (array $r): array => $this->presentText('html', $r, (string) $r['field']) + [
                    'preview_url'    => empty($r['source_url'])
                        ? null
                        : $this->frontendPreviewUrl((string) $r['source_url'], $lang),
                    'preview_target' => 'frontend',
                ],
                $this->repo->listHtmlTexts($lang, $search)
            );
        }

        if (preg_match('/^content:([0-9]+)$/', $id, $m)) {
            $postId = (int) $m[1];
            $post   = get_post($postId);
            if ($post && $this->isFrontPage($postId, $post->post_type, $post->post_name)) {
                $preview = [
                    'preview_url'    => $this->frontendPreviewUrl(home_url('/'), $lang),
                    'preview_target' => 'frontend',
                ];
            } elseif (get_post_type($postId) === 'attachment') {
                $preview = [
                    'preview_url'    => admin_url('post.php?post=' . $postId . '&action=edit'),
                    'preview_target' => 'admin',
                ];
            } else {
                $permalink = get_permalink($postId);
                $preview   = [
                    'preview_url'    => $permalink === false ? null : $this->frontendPreviewUrl($permalink, $lang),
                    'preview_target' => 'frontend',
                ];
            }
            $posts = array_map(
                fn (array $r): array => $this->presentText('post', $r, (string) $r['field']) + $preview,
                $this->repo->listPostTexts($postId, $lang, $search)
            );
            $metas = array_map(
                fn (array $r): array => $this->presentText('meta', $r, (string) $r['field']) + $preview,
                $this->repo->listMetaTexts($postId, $lang, $search)
            );
            return array_merge($posts, $metas);
        }

        if (preg_match('/^taxonomy:(.+)$/', $id, $m)) {
            $taxonomy = $m[1];
            return array_map(
                fn (array $r): array => $this->presentText('term', $r, (string) $r['field']) + [
                    'preview_url'    => admin_url(
                        'term.php?taxonomy=' . urlencode($taxonomy) . '&tag_ID=' . (int) $r['term_id']
                    ),
                    'preview_target' => 'admin',
                ],
                $this->repo->listTaxonomyTexts($taxonomy, $lang, $search)
            );
        }

        if (preg_match('/^plugin:(.+)$/', $id, $m)) {
            $rows = [];
            foreach ($this->pluginIdDomains($m[1], $lang, $search) as $domain) {
                $rows = array_merge($rows, $this->repo->listStaticTextDomainTexts($domain, $lang, $search));
            }
            return array_map(
                fn (array $r): array => $this->presentText('staticText', $r, (string) $r['field']) + [
                    'preview_url'    => null,
                    'preview_target' => null,
                ],
                $rows
            );
        }

        return null;
    }

    /**
     * Resolve a `plugin:{token}` collection-id token to the raw domain(s) it
     * covers: `__other__` expands to every domain not matching an installed
     * plugin/theme (the merged "Other" bucket); anything else is one literal,
     * rawurldecode()'d domain.
     *
     * @return string[]
     */
    private function pluginIdDomains(string $token, string $lang, string $search): array
    {
        $token = rawurldecode($token);
        if ($token === '__other__') {
            return $this->unmatchedTextDomains($lang, $search);
        }

        return [$token];
    }


    /**
     * Recomputed total/translated/needs_review/missing for one collection,
     * post-mutation — lets a mutation response patch the board's cached
     * summary directly instead of the caller re-fetching the whole page.
     * Reuses collectionTexts() so bucket semantics (reviewed + translated
     * both count as "translated") stay identical to the board's own numbers.
     */
    public function summaryFor(string $id, string $lang): ?array
    {
        $texts = $this->collectionTexts($id, $lang, '');
        if ($texts === null) {
            return null;
        }

        $summary = ['id' => $id, 'total' => count($texts), 'translated' => 0, 'needs_review' => 0, 'missing' => 0];
        foreach ($texts as $t) {
            if ($t['status'] === 'missing') {
                $summary['missing']++;
            } elseif ($t['status'] === 'needs_review') {
                $summary['needs_review']++;
            } else {
                // 'reviewed' and 'translated' share one aggregate bucket.
                $summary['translated']++;
            }
        }

        return $summary;
    }


    /**
     * The just-written unit (by its formatted SourceRef) plus the collection's
     * recomputed summary, in one collectionTexts() call — a single-text
     * mutation needs both to patch the client's cache directly.
     *
     * @return array{unit: ?array, summary: ?array}
     */
    public function unitAndSummaryFor(string $id, string $lang, string $refFormatted): array
    {
        $texts = $this->collectionTexts($id, $lang, '');
        if ($texts === null) {
            return ['unit' => null, 'summary' => null];
        }

        $unit    = null;
        $summary = ['id' => $id, 'total' => count($texts), 'translated' => 0, 'needs_review' => 0, 'missing' => 0];
        foreach ($texts as $t) {
            if ($t['id'] === $refFormatted) {
                $unit = $t;
            }
            if ($t['status'] === 'missing') {
                $summary['missing']++;
            } elseif ($t['status'] === 'needs_review') {
                $summary['needs_review']++;
            } else {
                $summary['translated']++;
            }
        }

        return ['unit' => $unit, 'summary' => $summary];
    }

    /**
     * Every matching group's text-unit ids, in board order (same group order
     * and filters as index(), each group's own text order within it). Powers
     * the compare overlay's cross-group prev/next — not part of the paginated
     * board itself, so it's only fetched when the overlay actually needs it.
     * Grouped (not a flat {id,cid} list) so the client can label a neighbor
     * group even if the board hasn't paginated its way to it yet.
     *
     * ponytail: reuses collectionTexts() per matching group, the same cost as
     * opening every one of them (a deliberate "walk everything" action, not
     * something called on scroll). If that cost bites at scale, project id
     * columns only instead of running full presentText() per row.
     */
    public function textOrder(WP_REST_Request $request): WP_REST_Response
    {
        $lang   = $this->resolveLang($request);
        $search = $this->resolveSearch($request);
        $status = $this->resolveStatus($request);
        $source = $this->resolveSource($request);

        if (! in_array($status, self::VALID_STATUSES, true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_STATUS'], 400);
        }
        if (! in_array($source, self::VALID_SOURCES, true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_SOURCE'], 400);
        }

        $collections = $this->buildCollections($lang, $search, $source);
        if ($status !== 'all') {
            $collections = array_values(array_filter($collections, static fn (array $c): bool => match ($status) {
                'untranslated' => $c['missing'] > 0,
                'needs_review' => $c['needs_review'] > 0,
                'translated'   => $c['translated'] > 0,
            }));
        }

        $groups = [];
        foreach ($collections as $c) {
            $ids = array_column($this->collectionTexts($c['id'], $lang, $search) ?? [], 'id');
            if ($ids === []) {
                continue;
            }
            $groups[] = [
                'cid'   => $c['id'],
                'label' => $c['label'],
                'type'  => $c['type'],
                'ids'   => $ids,
            ];
        }

        return rest_ensure_response(['ok' => true, 'groups' => $groups]);
    }

    /** Ignore every text in a collection (source-global). Confirmation-gated in the UI. */
    public function ignoreAll(WP_REST_Request $request): WP_REST_Response
    {
        $id   = urldecode((string) $request->get_param('id'));
        $lang = $this->resolveLang($request);
        $refs = $this->collectionRefs($id, $lang);
        if ($refs === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        foreach ($refs as $ref) {
            $this->repo->ignoreSource($ref);
        }

        return rest_ensure_response([
            'ok' => true,
            'count' => count($refs),
            'collection_summary' => $this->summaryFor($id, $lang),
        ]);
    }

    /** Clear every translation in a collection for the selected language. */
    public function clearAll(WP_REST_Request $request): WP_REST_Response
    {
        $lang = $this->resolveLang($request);
        if ($lang === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        $id   = urldecode((string) $request->get_param('id'));
        $refs = $this->collectionRefs($id, $lang);
        if ($refs === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        foreach ($refs as $ref) {
            $this->repo->deleteTranslation($ref, $lang);
        }

        return rest_ensure_response([
            'ok' => true,
            'count' => count($refs),
            'collection_summary' => $this->summaryFor($id, $lang),
        ]);
    }

    /**
     * Every source position in a collection as a SourceRef, or null for an unknown
     * id. Enumerates via the same list reads the board uses (collections are bounded,
     * so a per-ref loop is fine); reuses the tested ignore/clear code paths.
     *
     * @return SourceRef[]|null
     */
    private function collectionRefs(string $id, string $lang): ?array
    {
        if ($id === 'option:site') {
            return array_map(
                static fn (array $u): SourceRef => SourceRef::make('option', (int) $u['id']),
                $this->repo->listOptionTexts($lang)
            );
        }
        if ($id === 'html:all') {
            return array_map(
                static fn (array $u): SourceRef => SourceRef::make('html', (int) $u['id']),
                $this->repo->listHtmlTexts($lang)
            );
        }
        if (preg_match('/^content:([0-9]+)$/', $id, $m)) {
            $postId = (int) $m[1];
            $posts  = array_map(
                static fn (array $u): SourceRef => SourceRef::make('post', (int) $u['id']),
                $this->repo->listPostTexts($postId, $lang)
            );
            $metas = array_map(
                static fn (array $u): SourceRef => SourceRef::make('meta', (int) $u['id']),
                $this->repo->listMetaTexts($postId, $lang)
            );
            return array_merge($posts, $metas);
        }
        if (preg_match('/^taxonomy:(.+)$/', $id, $m)) {
            return array_map(
                static fn (array $u): SourceRef => SourceRef::make('term', (int) $u['id']),
                $this->repo->listTaxonomyTexts($m[1], $lang)
            );
        }
        if (preg_match('/^plugin:(.+)$/', $id, $m)) {
            $refs = [];
            foreach ($this->pluginIdDomains($m[1], $lang, '') as $domain) {
                foreach ($this->repo->listStaticTextDomainTexts($domain, $lang) as $u) {
                    $refs[] = SourceRef::make('staticText', (int) $u['id']);
                }
            }
            return $refs;
        }

        return null;
    }

    /** Shape one text unit for the board, deriving the UI status from schema state. */
    private function presentText(string $kind, array $row, string $field): array
    {
        $translated = $row['translated_text'];
        $status     = $row['status'];
        $provider   = $row['provider'];
        $original   = (string) $row['original_text'];

        if ($translated === null) {
            $statusLabel = 'missing';
        } elseif ($status === TranslationStatus::REVIEWED) {
            $statusLabel = 'reviewed';
        } elseif ($status === TranslationStatus::NEEDS_REVIEW) {
            $statusLabel = 'needs_review';
        } else {
            $statusLabel = 'translated';
        }

        $isAi = $translated !== null && $status === TranslationStatus::MACHINE && $provider !== null;

        return [
            'id'            => SourceRef::make($kind, (int) $row['id'])->format(),
            'field'         => $field,
            'original'      => $original,
            'translated'    => $translated,
            // v1: fallback shows the source text; fallback-language lookup is later.
            'fallback'      => $original,
            'status'        => $statusLabel,
            'origin'        => $translated === null ? null : ($provider !== null ? 'ai' : 'manual'),
            'is_ai'         => $isAi,
            'word_count'    => str_word_count(wp_strip_all_tags($original)),
            'char_count'    => mb_strlen($original),
            // Hash-link kinds (post/term/html/option) share one translation row
            // per (original_hash, lang) across every position with the same
            // text; null for parent_id kinds (meta), which never share. Lets
            // the client patch every other visible occurrence of this exact
            // text after a save, instead of only the one just edited.
            'original_hash' => $row['original_hash'] ?? null,
        ];
    }

    /**
     * Append the editor language-preview param to a front-end URL, so the page
     * renders in the selected target language (ResolveRequestLanguage::resolveFromPreview).
     */
    private function frontendPreviewUrl(string $base, string $lang): string
    {
        $sep = str_contains($base, '?') ? '&' : '?';

        return $base . $sep . 'translangs_preview=' . rawurlencode($lang);
    }

    /** Selected target language, defaulting to the first enabled target. */
    private function resolveLang(WP_REST_Request $request): string
    {
        $lang = sanitize_key((string) $request->get_param('language'));
        if ($lang !== '') {
            return $lang;
        }

        return $this->registry->getTargetLanguages()[0] ?? '';
    }

    /**
     * The board search term, or '' for no search.
     *
     * WP_REST_Server already unslashed $_GET, and the term is only ever bound as a
     * prepared LIKE parameter, so prepare() is the whole safety story.
     *
     * Deliberately NOT sanitize_text_field(): it strips every %xx sequence, so a
     * search for "100%ab" would silently become "100". wp_check_invalid_utf8 is the
     * one filter that belongs here — it returns '' for malformed input, which
     * degrades cleanly to "no search" instead of reaching MySQL.
     */
    private function resolveSearch(WP_REST_Request $request): string
    {
        $q = trim(wp_check_invalid_utf8((string) $request->get_param('search')));

        return mb_strlen($q) < self::SEARCH_MIN ? '' : $q;
    }


    /** The board status filter, defaulting to 'all'. Validity is index()'s job. */
    private function resolveStatus(WP_REST_Request $request): string
    {
        $status = sanitize_key((string) $request->get_param('status'));

        return $status === '' ? 'all' : $status;
    }

    /** Which board: 'content' (default) or 'plugins'. Validity is the caller's job. */
    private function resolveSource(WP_REST_Request $request): string
    {
        $source = sanitize_key((string) $request->get_param('source'));

        return $source === '' ? 'content' : $source;
    }

    /** Human-readable taxonomy name (plural label), falling back to the slug. */
    private function taxonomyLabel(string $taxonomy): string
    {
        $obj = get_taxonomy($taxonomy);
        if ($obj && ! empty($obj->labels->name)) {
            return (string) $obj->labels->name;
        }

        return $taxonomy;
    }

    /** Map a WordPress post_type to the workspace source group. */
    private function uxType(string $postType): string
    {
        if ($postType === 'page') {
            return 'page';
        }
        if ($postType === 'post') {
            return 'post';
        }

        return 'cpt';
    }

    /**
     * Domains present in tl_staticText that don't resolve to an installed
     * plugin/theme (WordPress core's `default` domain, or a removed plugin) —
     * the raw domains merged into the Plugins & Themes board's "Other" bucket.
     * Public so AiTranslationController can reuse it instead of re-implementing
     * plugin/theme resolution for its own `plugin:__other__` handling.
     *
     * @return string[]
     */
    public function unmatchedTextDomains(string $lang, string $search = ''): array
    {
        $known   = array_keys($this->resolveTextDomains());
        $present = array_column($this->repo->staticTextDomainSummary($lang, $search), 'domain');

        return array_values(array_diff($present, $known));
    }

    /**
     * Every installed plugin/theme's textdomain → {label, kind}, built fresh per
     * request (cheap: a handful of plugin headers + the active theme). Domains
     * with no match are simply absent — "Other" bucketing is the caller's job.
     *
     * @return array<string, array{label:string, kind:'plugin'|'theme'}>
     */
    private function resolveTextDomains(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $map = [];

        foreach (get_plugins() as $pluginFile => $data) {
            $domain = trim((string) ($data['TextDomain'] ?? ''));
            if ($domain === '') {
                // No TextDomain header: fall back to the plugin's own folder slug,
                // which is what WordPress itself uses to guess a domain at load time.
                $dir    = dirname($pluginFile);
                $domain = $dir === '.' ? '' : $dir;
            }
            if ($domain === '') {
                continue;
            }
            $map[$domain] = ['label' => (string) ($data['Name'] ?: $domain), 'kind' => 'plugin'];
        }

        $theme = wp_get_theme();
        foreach (array_filter([$theme, $theme->parent()]) as $t) {
            $domain = trim((string) $t->get('TextDomain'));
            if ($domain === '') {
                $domain = $t->get_stylesheet();
            }
            if ($domain !== '') {
                $map[$domain] = ['label' => (string) $t->get('Name'), 'kind' => 'theme'];
            }
        }

        return $map;
    }

    /**
     * Is this post the one WordPress renders at the site root (`/`)?
     *
     * Classic setup: a static page selected under Settings → Reading
     * (`show_on_front = page`, `page_on_front = <id>`). Block (FSE) theme with the
     * root showing posts (`show_on_front = posts`): the site root is rendered by the
     * `front-page` template, or by `home` when no `front-page` template exists.
     */
    private function isFrontPage(int $postId, string $postType, string $postName): bool
    {
        if (get_option('show_on_front') === 'page') {
            return $postId === (int) get_option('page_on_front');
        }

        return $postType === 'wp_template'
            && in_array($postName, ['front-page', 'home'], true);
    }
}

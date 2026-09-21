<?php

namespace LingoWP\Board;

use LingoWP\Language\Domain\LocaleNormalizer;

use LingoWP\AiTranslation\AiResultCollector;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Source\TranslationStatus;
use WP_REST_Request;
use WP_REST_Response;

final class CollectionsRestController
{
    private const NS = 'lingowp/v1';

    private const SEARCH_MIN = 2;

    private const VALID_STATUSES = ['all', 'untranslated', 'needs_review', 'translated', 'ignored'];

    private const VALID_SOURCES = ['content'];

    private const VALID_ORIGINS       = ['manual', 'ai', 'not_translated'];
    private const VALID_CONTENT_TYPES = ['page', 'post', 'cpt', 'taxonomy', 'media', 'site_settings', 'html', 'plugin', 'theme', 'author_bios'];
    private const VALID_TEXT_LENGTHS = ['short', 'medium', 'long', 'length_mismatch'];
    private const VALID_PROGRESS     = ['not_started', 'in_progress', 'complete', 'has_review'];

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 100;

    private WpDbSourceRepository $repo;
    private OptionLanguageRegistry $registry;
    private CollectionBoard $board;
    private AiResultCollector $aiResults;

    public function __construct(
        WpDbSourceRepository $repo,
        OptionLanguageRegistry $registry,
        CollectionBoard $board,
        AiResultCollector $aiResults
    ) {
        $this->repo      = $repo;
        $this->registry  = $registry;
        $this->board     = $board;
        $this->aiResults = $aiResults;
    }

    private function maybeCollectAiResults(): void
    {
        if (! $this->repo->hasAnyPending()) {
            return;
        }
        if (! $this->repo->tryAcquireAiPullLock()) {
            return;
        }
        try {
            $this->aiResults->collectOnce();
        } finally {
            $this->repo->releaseAiPullLock();
        }
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NS, '/collections', ['methods' => 'GET', 'callback' => [$this, 'index'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(
            self::NS,
            '/collections/text-order',
            ['methods' => 'GET', 'callback' => [$this, 'textOrder'], 'permission_callback' => [$this, 'canManage']]
        );
        register_rest_route(
            self::NS,
            '/collections/(?P<id>[^/]+)/texts',
            ['methods' => 'GET', 'callback' => [$this, 'texts'], 'permission_callback' => [$this, 'canManage']]
        );
        register_rest_route(
            self::NS,
            '/collections/(?P<id>[^/]+)/ignore',
            ['methods' => 'POST', 'callback' => [$this, 'ignoreAll'], 'permission_callback' => [$this, 'canManage']]
        );
        register_rest_route(
            self::NS,
            '/collections/(?P<id>[^/]+)/clear',
            ['methods' => 'POST', 'callback' => [$this, 'clearAll'], 'permission_callback' => [$this, 'canManage']]
        );
        register_rest_route(
            self::NS,
            '/collections/(?P<id>[^/]+)/approve',
            ['methods' => 'POST', 'callback' => [$this, 'approveAll'], 'permission_callback' => [$this, 'canManage']]
        );
        register_rest_route(
            self::NS,
            '/collections/(?P<id>[^/]+)/stale',
            ['methods' => 'GET', 'callback' => [$this, 'staleTexts'], 'permission_callback' => [$this, 'canManage']]
        );
        register_rest_route(
            self::NS,
            '/collections/(?P<id>[^/]+)/delete-stale',
            ['methods' => 'POST', 'callback' => [$this, 'deleteStale'], 'permission_callback' => [$this, 'canManage']]
        );
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $this->maybeCollectAiResults();

        $lang    = $this->resolveLang($request);
        $search  = $this->resolveSearch($request);
        $status  = $this->resolveStatus($request);
        $source  = $this->resolveSource($request);
        $filters = $this->resolveFilters($request);

        if (! in_array($status, self::VALID_STATUSES, true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_STATUS'], 400);
        }
        if (! in_array($source, self::VALID_SOURCES, true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_SOURCE'], 400);
        }
        if ($filters === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_FILTER'], 400);
        }

        $offset = max(0, (int) $request->get_param('offset'));
        $limit  = min(self::MAX_LIMIT, max(1, (int) ($request->get_param('limit') ?: self::DEFAULT_LIMIT)));

        if ($status === 'ignored') {
            $collections = $this->ignoredCollections($lang, $search);
        } else {
            $collections = $this->board->buildCollections($lang, $search, $source, $filters);
            $collections = array_values(array_filter(
                $collections,
                fn (array $collection): bool => $this->board->collectionMatchesFilters($collection, $filters)
            ));

            if ($status !== 'all') {
                $collections = array_values(array_filter($collections, static function (array $c) use ($status): bool {
                    switch ($status) {
                        case 'untranslated':
                            return $c['missing'] > 0 || ($c['pending'] ?? 0) > 0;
                        case 'needs_review':
                            return $c['needs_review'] > 0;
                        case 'translated':
                            return $c['translated'] > 0;
                        default:
                            throw new \RuntimeException('Unhandled match case: ' . var_export($status, true));
                    }
                }));
            }
        }

        $totalTexts      = array_sum(array_column($collections, 'total'));
        $totalTranslated = array_sum(array_column($collections, 'translated'));

        $total    = count($collections);
        $page     = array_slice($collections, $offset, $limit);
        $returned = count($page);
        $next     = $offset + $returned;

        return rest_ensure_response([
            'ok'               => true,
            'language'         => $lang,
            'search'           => $search,
            'status'           => $status,
            'source'           => $source,
            'filters'          => $filters,
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

    public function texts(WP_REST_Request $request): WP_REST_Response
    {
        $this->maybeCollectAiResults();

        $lang    = $this->resolveLang($request);
        $search  = $this->resolveSearch($request);
        $status  = $this->resolveStatus($request);
        $id      = urldecode((string) $request->get_param('id'));
        $filters = $this->resolveFilters($request);

        if (! in_array($status, self::VALID_STATUSES, true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_STATUS'], 400);
        }
        if ($filters === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_FILTER'], 400);
        }

        $texts = $this->board->collectionTexts($id, $lang, $search, $filters);
        if ($texts === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        if ($status !== 'all' && $status !== 'ignored') {
            $texts = array_values(array_filter($texts, static function (array $t) use ($status): bool {
                switch ($status) {
                    case 'untranslated':
                        return $t['status'] === 'missing' || $t['status'] === 'pending';
                    case 'needs_review':
                        return $t['status'] === 'needs_review';
                    case 'translated':
                        return $t['status'] === 'translated' || $t['status'] === 'reviewed';
                    default:
                        throw new \RuntimeException('Unhandled match case: ' . var_export($status, true));
                }
            }));
        }

        return rest_ensure_response([
            'ok'       => true,
            'language' => $lang,
            'search'   => $search,
            'status'   => $status,
            'filters'  => $filters,
            'texts'    => $texts,
        ]);
    }

    public function textOrder(WP_REST_Request $request): WP_REST_Response
    {
        $lang    = $this->resolveLang($request);
        $search  = $this->resolveSearch($request);
        $status  = $this->resolveStatus($request);
        $source  = $this->resolveSource($request);
        $filters = $this->resolveFilters($request);

        if (! in_array($status, self::VALID_STATUSES, true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_STATUS'], 400);
        }
        if (! in_array($source, self::VALID_SOURCES, true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_SOURCE'], 400);
        }
        if ($filters === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_FILTER'], 400);
        }

        if ($status === 'ignored') {
            $collections = $this->ignoredCollections($lang, $search);
        } else {
            $collections = $this->board->buildCollections($lang, $search, $source, $filters);
            $collections = array_values(array_filter(
                $collections,
                fn (array $collection): bool => $this->board->collectionMatchesFilters($collection, $filters)
            ));
            if ($status !== 'all') {
                $collections = array_values(array_filter($collections, static function (array $c) use ($status): bool {
                    switch ($status) {
                        case 'untranslated':
                            return $c['missing'] > 0 || ($c['pending'] ?? 0) > 0;
                        case 'needs_review':
                            return $c['needs_review'] > 0;
                        case 'translated':
                            return $c['translated'] > 0;
                        default:
                            throw new \RuntimeException('Unhandled match case: ' . var_export($status, true));
                    }
                }));
            }
        }

        $groups = [];
        foreach ($collections as $c) {
            $ids = array_column($this->board->collectionTexts($c['id'], $lang, $search, $filters, false) ?? [], 'id');
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

    private function ignoredCollections(string $lang, string $search): array
    {
        $texts = $this->board->collectionTexts('ignored:all', $lang, $search, []) ?? [];
        if ($texts === []) {
            return [];
        }
        $count = count($texts);

        return [[
            'id'             => 'ignored:all',
            'label'          => __('Ignored', 'lingowp'),
            'type'           => 'ignored',
            'preview_url'    => null,
            'preview_target' => 'admin',
            'total'          => $count,
            'translated'     => 0,
            'needs_review'   => 0,
            'pending'        => 0,
            'missing'        => $count,
        ]];
    }

    public function ignoreAll(WP_REST_Request $request): WP_REST_Response
    {
        $id   = urldecode((string) $request->get_param('id'));
        $lang = $this->resolveLang($request);
        $refs = $this->board->collectionRefs($id, $lang);
        if ($refs === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        foreach ($refs as $ref) {
            $this->repo->ignoreSource($ref);
        }

        return rest_ensure_response([
            'ok' => true,
            'count' => count($refs),
            'collection_summary' => $this->board->summaryFor($id, $lang),
        ]);
    }

    public function clearAll(WP_REST_Request $request): WP_REST_Response
    {
        $lang = $this->resolveLang($request);
        if ($lang === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        $id   = urldecode((string) $request->get_param('id'));
        $refs = $this->board->collectionRefs($id, $lang);
        if ($refs === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        foreach ($refs as $ref) {
            $this->repo->deleteTranslation($ref, $lang);
        }

        return rest_ensure_response([
            'ok' => true,
            'count' => count($refs),
            'collection_summary' => $this->board->summaryFor($id, $lang),
        ]);
    }

    public function approveAll(WP_REST_Request $request): WP_REST_Response
    {
        $lang = $this->resolveLang($request);
        if ($lang === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        $id    = urldecode((string) $request->get_param('id'));
        $texts = $this->board->collectionTexts($id, $lang, '', [], false);
        if ($texts === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $approved = 0;
        foreach ($texts as $text) {
            if ($text['status'] !== 'needs_review') {
                continue;
            }
            $ref = SourceRef::parse((string) $text['id']);
            if ($ref === null) {
                continue;
            }
            $this->repo->setTranslationStatus($ref, $lang, TranslationStatus::REVIEWED);
            $approved++;
        }

        return rest_ensure_response([
            'ok' => true,
            'count' => $approved,
            'collection_summary' => $this->board->summaryFor($id, $lang),
        ]);
    }

    public function staleTexts(WP_REST_Request $request): WP_REST_Response
    {
        $id = urldecode((string) $request->get_param('id'));
        if ($id !== 'html:all') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $lang   = $this->resolveLang($request);
        $search = $this->resolveSearch($request);
        $texts  = array_map(
            fn (array $r): array => $this->board->presentText('html', $r, (string) $r['field']) + [
                'preview_url'    => $this->board->htmlPreviewUrl($r['source_url'] ?? null, $lang),
                'preview_target' => 'frontend',
            ],
            $this->repo->listStaleHtmlSources($lang, $search)
        );

        return rest_ensure_response(['ok' => true, 'language' => $lang, 'texts' => $texts]);
    }

    public function deleteStale(WP_REST_Request $request): WP_REST_Response
    {
        $id = urldecode((string) $request->get_param('id'));
        if ($id !== 'html:all') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $lang = $this->resolveLang($request);
        $ids  = array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->repo->listStaleHtmlSources($lang)
        );
        $deleted = $this->repo->deleteStaleHtmlSources($ids);

        return rest_ensure_response([
            'ok'                  => true,
            'count'               => $deleted,
            'collection_summary'  => $this->board->summaryFor($id, $lang),
        ]);
    }

    private function resolveLang(WP_REST_Request $request): string
    {
        $lang = LocaleNormalizer::normalize((string) $request->get_param('language'));
        if ($lang !== '') {
            return $lang;
        }

        return $this->registry->getTargetLanguages()[0] ?? '';
    }

    private function resolveSearch(WP_REST_Request $request): string
    {
        $q = trim(wp_check_invalid_utf8((string) $request->get_param('search')));

        return mb_strlen($q) < self::SEARCH_MIN ? '' : $q;
    }

    private function resolveFilters(WP_REST_Request $request): ?array
    {
        $definitions = [
            'origin'       => self::VALID_ORIGINS,
            'content_type' => self::VALID_CONTENT_TYPES,
            'text_length'  => self::VALID_TEXT_LENGTHS,
            'progress'     => self::VALID_PROGRESS,
        ];
        $filters = [];

        foreach ($definitions as $key => $allowed) {
            $raw    = $request->get_param($key);
            $values = is_array($raw)
                ? $raw
                : preg_split('/\s*,\s*/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
            $values = array_values(array_unique(array_filter(array_map(
                static fn ($value): string => sanitize_key((string) $value),
                $values ?: []
            ))));

            if (array_diff($values, $allowed) !== []) {
                return null;
            }
            $filters[$key] = $values;
        }

        return $filters;
    }

    private function resolveStatus(WP_REST_Request $request): string
    {
        $status = sanitize_key((string) $request->get_param('status'));

        return $status === '' ? 'all' : $status;
    }

    private function resolveSource(WP_REST_Request $request): string
    {
        $source = sanitize_key((string) $request->get_param('source'));

        return $source === '' ? 'content' : $source;
    }
}

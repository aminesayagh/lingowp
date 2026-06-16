<?php

namespace TransLangs\BackOffice\Infrastructure\Rest;

use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\TranslationMemory\Domain\SourceTranslationRepository;
use TransLangs\TranslationMemory\Domain\TranslationStatus;
use TransLangs\TranslationMemory\Infrastructure\Repositories\AdminBoardQuery;
use TransLangs\TranslationMemory\Infrastructure\StructuredSourceScanner;

/**
 * Admin board REST API (spec: translangs_api_admin_board.md), namespace
 * translangs/v1. Adapted to the v4 code-based model: languages travel as codes
 * (no lang_id), provider is a free string. source_key travels as lowercase hex.
 *
 * Every route requires manage_options; the SPA sends the wp_rest cookie nonce
 * (X-WP-Nonce), enforced by core. current_hash is always read server-side.
 */
final class AdminRestController
{
    private const NS  = 'translangs/v1';
    private const KEY = '(?P<key>[0-9a-fA-F]{64})';

    public function __construct(
        private AdminBoardQuery $query,
        private SourceTranslationRepository $repository,
        private LanguageRegistry $languages,
        private StructuredSourceScanner $scanner
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NS, '/config', $this->route('GET', 'config'));
        register_rest_route(self::NS, '/items', $this->route('GET', 'items'));
        register_rest_route(self::NS, '/counts', $this->route('GET', 'counts'));
        register_rest_route(self::NS, '/counts-all', $this->route('GET', 'countsAll'));
        register_rest_route(self::NS, '/ignored', $this->route('GET', 'ignored'));
        register_rest_route(self::NS, '/ignored/restore', $this->route('POST', 'restore'));
        register_rest_route(self::NS, '/scan', $this->route('POST', 'scan'));

        register_rest_route(self::NS, '/string/' . self::KEY, $this->route('GET', 'getString'));
        register_rest_route(self::NS, '/string/' . self::KEY . '/translation', [
            $this->method('PUT', 'putTranslation'),
            $this->method('DELETE', 'deleteTranslation'),
        ]);
        register_rest_route(self::NS, '/string/' . self::KEY . '/translations', $this->route('PUT', 'putTranslations'));
        register_rest_route(self::NS, '/string/' . self::KEY . '/ignore', $this->route('POST', 'ignore'));
        register_rest_route(self::NS, '/string/' . self::KEY . '/duplicates', $this->route('GET', 'duplicates'));
        register_rest_route(self::NS, '/string/' . self::KEY . '/apply-everywhere', $this->route('POST', 'applyEverywhere'));
        register_rest_route(self::NS, '/string/' . self::KEY . '/preview', $this->route('GET', 'preview'));
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    // ── reads ─────────────────────────────────────────────────────────────────

    public function config(): \WP_REST_Response
    {
        $languages = [];
        foreach ($this->languages->getTargetLanguageDetails() as $code => $meta) {
            $languages[] = [
                'code'   => $code,
                'name'   => $meta['name'],
                'native' => $meta['native'],
                'dir'    => $meta['dir'],
            ];
        }

        return rest_ensure_response([
            'languages'     => $languages,
            'default'       => $this->languages->getDefaultLanguage(),
            'content_areas' => [
                ['key' => 'page', 'label' => 'Pages'],
                ['key' => 'post', 'label' => 'Posts'],
                ['key' => 'product', 'label' => 'Products'],
                ['key' => 'term', 'label' => 'Categories'],
                ['key' => 'media', 'label' => 'Media'],
                ['key' => 'i18n', 'label' => 'Theme & button text'],
                ['key' => 'html', 'label' => 'Templates'],
            ],
            'providers'     => [],
        ]);
    }

    public function items(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $area = AdminBoardQuery::area((string) $request->get_param('area'));
        if ($area === null) {
            return $this->error('invalid_area', 'Unknown content area.', 400);
        }
        $lang = $this->targetLang($request);
        if ($lang instanceof \WP_Error) {
            return $lang;
        }

        $tab = (string) ($request->get_param('tab') ?: 'to_translate');
        if (!in_array($tab, ['to_translate', 'to_review', 'done'], true)) {
            $tab = 'to_translate';
        }

        $result = $this->query->items([
            'types'        => $area['types'],
            'post_type'    => $area['post_type'],
            'lang'         => $lang,
            'tab'          => $tab,
            'q_original'   => (string) $request->get_param('q_original'),
            'q_translated' => (string) $request->get_param('q_translated'),
            'origin'       => (string) ($request->get_param('origin') ?: 'all'),
            'cursor'       => KeysetCursor::decodeOwner($request->get_param('cursor')),
            'limit'        => (int) ($request->get_param('limit') ?: 20),
        ]);

        return rest_ensure_response([
            'groups'      => $this->groupRows($result['rows']),
            'next_cursor' => KeysetCursor::encodeOwner($result['next_cursor']),
            'counts'      => $this->query->counts($area['types'], $area['post_type'], $lang),
        ]);
    }

    public function counts(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $area = AdminBoardQuery::area((string) $request->get_param('area'));
        if ($area === null) {
            return $this->error('invalid_area', 'Unknown content area.', 400);
        }
        $lang = $this->targetLang($request);
        if ($lang instanceof \WP_Error) {
            return $lang;
        }

        return rest_ensure_response($this->query->counts($area['types'], $area['post_type'], $lang));
    }

    /** Per-area {to_translate, total} for the sidebar badges, for one language. */
    public function countsAll(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $lang = $this->targetLang($request);
        if ($lang instanceof \WP_Error) {
            return $lang;
        }

        $out = [];
        foreach (['page', 'post', 'product', 'term', 'media', 'i18n', 'html'] as $key) {
            $area = AdminBoardQuery::area($key);
            if ($area === null) {
                continue;
            }
            $counts      = $this->query->counts($area['types'], $area['post_type'], $lang);
            $out[$key] = [
                'to_translate' => $counts['to_translate'],
                'total'        => $counts['total'],
            ];
        }

        return rest_ensure_response($out);
    }

    public function getString(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $key = $this->key($request);
        if ($key instanceof \WP_Error) {
            return $key;
        }
        $lang = $this->langParam($request);

        $row = $this->query->loadString($key, $lang);
        if ($row === null) {
            return $this->error('not_found', 'Source not found.', 404);
        }

        return rest_ensure_response([
            'string'   => $this->presentString($row),
            'siblings' => $this->query->siblings($key),
        ]);
    }

    public function duplicates(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $key = $this->key($request);
        if ($key instanceof \WP_Error) {
            return $key;
        }
        $lang = $this->targetLang($request);
        if ($lang instanceof \WP_Error) {
            return $lang;
        }

        $row = $this->query->loadString($key, $lang);
        if ($row === null) {
            return $this->error('not_found', 'Source not found.', 404);
        }
        $currentHash = HexSourceKey::toBinary((string) $row['current_hash']);

        return rest_ensure_response(
            $this->query->duplicates($key, (int) $row['type'], (string) $currentHash, $lang)
        );
    }

    public function ignored(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = $this->query->ignored(
            KeysetCursor::decodeId($request->get_param('cursor')),
            (int) ($request->get_param('limit') ?: 50)
        );

        return rest_ensure_response([
            'rows'        => $result['rows'],
            'next_cursor' => KeysetCursor::encodeId($result['next_cursor']),
        ]);
    }

    public function preview(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $key = $this->key($request);
        if ($key instanceof \WP_Error) {
            return $key;
        }
        $lang = $this->langParam($request);

        $row = $this->query->loadString($key, $lang);
        if ($row === null) {
            return $this->error('not_found', 'Source not found.', 404);
        }

        $focus = bin2hex($key);

        $type = (int) $row['type'];
        if ($type === 5) {
            $htmlUrl = (string) ($row['source_url'] ?? '');
            if ($htmlUrl !== '') {
                $htmlUrl = add_query_arg(['translangs_preview' => $lang, 'translangs_focus' => $focus], $htmlUrl);
            }

            return rest_ensure_response([
                'url'      => $htmlUrl,
                'selector' => (string) $row['ref1'],
            ]);
        }

        $url = '';
        if ($type === 3) {
            $term = function_exists('get_term_by') ? get_term_by('term_taxonomy_id', (int) $row['term_taxonomy_id']) : null;
            $link = $term instanceof \WP_Term && function_exists('get_term_link') ? get_term_link($term) : '';
            $url  = is_string($link) ? $link : '';
        } elseif (function_exists('get_permalink')) {
            $url = (string) get_permalink((int) $row['post_id']);
        }

        if ($url !== '') {
            // The editor-gated preview param (resolveFromPreview), not the
            // path-prefix query var translangs_lang — a bare ?translangs_lang
            // on a normal permalink can be dropped by canonical redirect.
            $url = add_query_arg(['translangs_preview' => $lang, 'translangs_focus' => $focus], $url);
        }

        return rest_ensure_response(['url' => $url, 'selector' => null]);
    }

    // ── writes ──────────────────────────────────────────────────────────────

    public function putTranslation(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $key = $this->key($request);
        if ($key instanceof \WP_Error) {
            return $key;
        }
        $lang = $this->targetLang($request);
        if ($lang instanceof \WP_Error) {
            return $lang;
        }

        $row = $this->query->loadString($key, $lang);
        if ($row === null) {
            return $this->error('not_found', 'Source not found.', 404);
        }

        $text = (string) $request->get_param('text');
        if (!$this->placeholdersPresent((string) $row['original_text'], $text)) {
            return $this->error('placeholder_mismatch', 'Formatting placeholders are missing from the translation.', 400);
        }

        $expected = $request->get_param('updated_at');
        if (is_string($expected) && $expected !== '') {
            $current = $this->repository->translationUpdatedAt($key, $lang);
            if ($current !== null && $current !== $expected) {
                return $this->error('conflict', 'This translation was changed elsewhere.', 409, ['server' => $row]);
            }
        }

        $this->repository->saveTranslation($key, $lang, $text, $this->markStatus((string) $request->get_param('mark')), null);

        $fresh = $this->query->loadString($key, $lang);

        return rest_ensure_response(['string' => $fresh === null ? null : $this->presentString($fresh)]);
    }

    public function deleteTranslation(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $key = $this->key($request);
        if ($key instanceof \WP_Error) {
            return $key;
        }
        $lang = $this->targetLang($request);
        if ($lang instanceof \WP_Error) {
            return $lang;
        }

        $this->repository->deleteTranslation($key, $lang);

        return rest_ensure_response(['ok' => true]);
    }

    public function putTranslations(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $key = $this->key($request);
        if ($key instanceof \WP_Error) {
            return $key;
        }

        $original = $this->query->loadString($key, $this->languages->getDefaultLanguage());
        if ($original === null) {
            return $this->error('not_found', 'Source not found.', 404);
        }
        $originalText = (string) $original['original_text'];

        $targets = $this->languages->getTargetLanguages();
        $saved   = 0;
        foreach ((array) $request->get_param('translations') as $entry) {
            $lang = is_array($entry) ? (string) ($entry['lang'] ?? '') : '';
            $text = is_array($entry) ? (string) ($entry['text'] ?? '') : '';
            if ($text === '' || !in_array($lang, $targets, true)) {
                continue;
            }
            if (!$this->placeholdersPresent($originalText, $text)) {
                return $this->error('placeholder_mismatch', "Formatting placeholders missing for '{$lang}'.", 400);
            }
            $this->repository->saveTranslation($key, $lang, $text, TranslationStatus::NEEDS_REVIEW, null);
            $saved++;
        }

        return rest_ensure_response(['saved' => $saved]);
    }

    public function ignore(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $key = $this->key($request);
        if ($key instanceof \WP_Error) {
            return $key;
        }
        $this->repository->ignoreSource($key);

        return rest_ensure_response(['ok' => true]);
    }

    public function restore(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $keys = [];
        foreach ((array) $request->get_param('keys') as $hex) {
            $bin = HexSourceKey::toBinary((string) $hex);
            if ($bin === null) {
                return $this->error('invalid_key', 'Malformed source key.', 400);
            }
            $keys[] = $bin;
        }
        $this->repository->restoreSources($keys);

        return rest_ensure_response(['ok' => true, 'restored' => count($keys)]);
    }

    public function applyEverywhere(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $key = $this->key($request);
        if ($key instanceof \WP_Error) {
            return $key;
        }
        $lang = $this->targetLang($request);
        if ($lang instanceof \WP_Error) {
            return $lang;
        }

        $targets = [];
        foreach ((array) $request->get_param('targets') as $hex) {
            $bin = HexSourceKey::toBinary((string) $hex);
            if ($bin === null) {
                return $this->error('invalid_key', 'Malformed target key.', 400);
            }
            $targets[] = $bin;
        }

        $applied = $this->repository->applyTranslationToKeys(
            $targets,
            $lang,
            (string) $request->get_param('text'),
            TranslationStatus::NEEDS_REVIEW,
            (bool) $request->get_param('overwrite')
        );

        return rest_ensure_response(['applied' => $applied]);
    }

    public function scan(\WP_REST_Request $request): \WP_REST_Response
    {
        $scope = $request->get_param('scope');
        if (is_array($scope) && isset($scope['owner_id']) && (int) $scope['owner_id'] > 0) {
            $this->scanner->refreshPost((int) $scope['owner_id']);
            return rest_ensure_response(['ok' => true, 'scope' => 'owner']);
        }

        $result = $this->scanner->scan();

        return rest_ensure_response([
            'ok'       => true,
            'sources'  => $result->totalSourceStrings(),
            'inserted' => $result->totalInsertedRows(),
            'skipped'  => $result->skipped,
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @return array{methods:string,callback:array,permission_callback:array} */
    private function route(string $method, string $handler): array
    {
        return $this->method($method, $handler);
    }

    /** @return array{methods:string,callback:array,permission_callback:array} */
    private function method(string $method, string $handler): array
    {
        return [
            'methods'             => $method,
            'callback'            => [$this, $handler],
            'permission_callback' => [$this, 'canManage'],
        ];
    }

    private function key(\WP_REST_Request $request): string|\WP_Error
    {
        $bin = HexSourceKey::toBinary((string) $request->get_param('key'));

        return $bin ?? $this->error('invalid_key', 'Malformed source key.', 400);
    }

    /** A target language code, or 400. Writes/lists are scoped to target languages. */
    private function targetLang(\WP_REST_Request $request): string|\WP_Error
    {
        $lang = (string) $request->get_param('lang');
        if (!in_array($lang, $this->languages->getTargetLanguages(), true)) {
            return $this->error('invalid_lang', 'Unknown or inactive target language.', 400);
        }

        return $lang;
    }

    private function langParam(\WP_REST_Request $request): string
    {
        return (string) $request->get_param('lang');
    }

    private function markStatus(string $mark): int
    {
        return match ($mark) {
            'machine'      => TranslationStatus::MACHINE,
            'needs_review' => TranslationStatus::NEEDS_REVIEW,
            default        => TranslationStatus::REVIEWED,
        };
    }

    /** Every {n}/{/n}/{n/} token in the original must be present in the text (UC-AD14). */
    private function placeholdersPresent(string $original, string $text): bool
    {
        if (preg_match_all('/\{\/?\d+\/?\}/', $original, $matches) === false) {
            return true;
        }
        foreach (array_unique($matches[0]) as $token) {
            if (strpos($text, $token) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function groupRows(array $rows): array
    {
        $groups = [];
        $order  = [];

        foreach ($rows as $row) {
            $type = (int) $row['type'];
            if ($type === 1 || $type === 2) {
                $ownerKey = 'p' . (int) $row['post_id'];
            } elseif ($type === 3) {
                $ownerKey = 't' . (int) $row['term_taxonomy_id'];
            } else {
                $ownerKey = 's' . (int) $row['id']; // gettext/html: each string is its own card
            }

            if (!isset($groups[$ownerKey])) {
                $groups[$ownerKey] = ['owner' => $this->ownerInfo($row), 'items' => []];
                $order[]           = $ownerKey;
            }

            $groups[$ownerKey]['items'][] = [
                'key'         => $row['source_key'],
                'field'       => $row['ref1'],
                'context'     => $row['ref2'],
                'original'    => $row['original_text'],
                'translation' => $row['translated_text'],
                'state'       => $row['effective_state'],
                'updated_at'  => $row['tr_updated'] ?? null,
                'preview'     => $this->previewable($type, (string) ($row['source_url'] ?? '')),
                'origin'      => $row['translated_text'] === null
                    ? null
                    : ($row['provider'] !== null ? 'machine' : 'human'),
            ];
        }

        return array_map(static fn(string $k): array => $groups[$k], $order);
    }

    /** Theme/button text (type 4) has no live URL; HTML (type 5) needs a source_url. */
    private function previewable(int $type, string $sourceUrl): bool
    {
        return match ($type) {
            4 => false,
            5 => $sourceUrl !== '',
            default => true,
        };
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function ownerInfo(array $row): array
    {
        $type = (int) $row['type'];

        if (($type === 1 || $type === 2) && function_exists('get_post')) {
            $post = get_post((int) $row['post_id']);
            return [
                'id'    => (int) $row['post_id'],
                'label' => $post instanceof \WP_Post ? $post->post_title : ('#' . (int) $row['post_id']),
                'kind'  => $post instanceof \WP_Post ? $post->post_type : 'post',
            ];
        }

        if ($type === 3 && function_exists('get_term_by')) {
            $term = get_term_by('term_taxonomy_id', (int) $row['term_taxonomy_id']);
            return [
                'id'    => (int) $row['term_taxonomy_id'],
                'label' => $term instanceof \WP_Term ? $term->name : ('#' . (int) $row['term_taxonomy_id']),
                'kind'  => $term instanceof \WP_Term ? $term->taxonomy : 'term',
            ];
        }

        return ['id' => (int) $row['id'], 'label' => (string) $row['ref1'], 'kind' => $type === 4 ? 'i18n' : 'html'];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentString(array $row): array
    {
        preg_match_all('/\{(\d+)\}/', (string) $row['original_text'], $matches);
        $placeholders = array_map(static fn(string $n): array => ['n' => (int) $n], array_unique($matches[1]));

        return [
            'key'          => $row['source_key'],
            'type'         => (int) $row['type'],
            'field'        => $row['ref1'],
            'context'      => $row['ref2'],
            'original'     => $row['original_text'],
            'translation'  => $row['translated_text'],
            'state'        => $row['effective_state'] ?? null,
            'updated_at'   => $row['tr_updated'] ?? null,
            'placeholders' => array_values($placeholders),
        ];
    }

    private function error(string $code, string $message, int $status, array $data = []): \WP_Error
    {
        return new \WP_Error($code, $message, array_merge(['status' => $status], $data));
    }
}

<?php

namespace TransLangs\BackOffice\Infrastructure\Rest;

use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\TranslationMemory\Domain\SourceRef;
use TransLangs\TranslationMemory\Domain\SourceTranslationRepository;
use TransLangs\TranslationMemory\Domain\TranslationStatus;
use TransLangs\TranslationMemory\Infrastructure\OptionSourceScanner;
use TransLangs\TranslationMemory\Infrastructure\Repositories\AdminBoardQuery;
use TransLangs\TranslationMemory\Infrastructure\StructuredSourceScanner;

/**
 * Admin board REST API, namespace translangs/v1.
 *
 * v5 is table-driven: a source is addressed by a SourceRef ({kind}:{id}) instead
 * of a hex source_key, and per-kind behavior (owner grouping) switches on the
 * kind token — there is no numeric source type. Languages travel as codes,
 * provider is a free string. Every route requires manage_options; the SPA sends
 * the wp_rest cookie nonce (X-WP-Nonce).
 */
final class AdminRestController
{
    private const NS  = 'translangs/v1';
    /** A SourceRef: {kind}:{id}, e.g. post:123 / staticText:9. */
    private const KEY = '(?P<key>[a-zA-Z]+:[0-9]+)';

    public function __construct(
        private AdminBoardQuery $query,
        private SourceTranslationRepository $repository,
        private LanguageRegistry $languages,
        private StructuredSourceScanner $scanner,
        private OptionSourceScanner $optionScanner
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
        register_rest_route(self::NS, '/export', $this->route('GET', 'export'));
        register_rest_route(self::NS, '/import', $this->route('POST', 'import'));

        register_rest_route(self::NS, '/string/' . self::KEY, $this->route('GET', 'getString'));
        register_rest_route(self::NS, '/string/' . self::KEY . '/translation', [
            $this->method('PUT', 'putTranslation'),
            $this->method('DELETE', 'deleteTranslation'),
        ]);
        register_rest_route(self::NS, '/string/' . self::KEY . '/translations', $this->route('PUT', 'putTranslations'));
        register_rest_route(self::NS, '/string/' . self::KEY . '/ignore', $this->route('POST', 'ignore'));
        register_rest_route(self::NS, '/string/' . self::KEY . '/duplicates', $this->route('GET', 'duplicates'));
        register_rest_route(self::NS, '/string/' . self::KEY . '/apply-everywhere', $this->route('POST', 'applyEverywhere'));
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
                ['key' => 'staticText', 'label' => 'Static text'],
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

        $sort = (string) $request->get_param('sort');
        if (!in_array($sort, ['default', 'recent'], true)) {
            $sort = 'default';
        }

        $result = $this->query->items([
            'post_type'    => $area['post_type'],
            'tables'       => $area['tables'],
            'lang'         => $lang,
            'tab'          => $tab,
            'sort'         => $sort,
            'q_original'   => (string) $request->get_param('q_original'),
            'q_translated' => (string) $request->get_param('q_translated'),
            'origin'       => (string) ($request->get_param('origin') ?: 'all'),
            'cursor'       => KeysetCursor::decodeOwner($request->get_param('cursor')),
            'limit'        => (int) ($request->get_param('limit') ?: 20),
        ]);

        return rest_ensure_response([
            'groups'      => $this->groupRows($result['rows']),
            'next_cursor' => KeysetCursor::encodeOwner($result['next_cursor']),
            'counts'      => $this->query->counts($area, $lang),
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

        return rest_ensure_response($this->query->counts($area, $lang));
    }

    /** Per-area {done, total} for the sidebar badges, for one language. */
    public function countsAll(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $lang = $this->targetLang($request);
        if ($lang instanceof \WP_Error) {
            return $lang;
        }

        $out = [];
        foreach (AdminBoardQuery::areaKeys() as $key) {
            $area = AdminBoardQuery::area($key);
            if ($area === null) {
                continue;
            }
            $counts    = $this->query->counts($area, $lang);
            $out[$key] = [
                'done'  => $counts['done'],
                'total' => $counts['total'],
            ];
        }

        return rest_ensure_response($out);
    }

    public function getString(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $ref = $this->ref($request);
        if ($ref instanceof \WP_Error) {
            return $ref;
        }
        $lang = $this->langParam($request);

        $row = $this->query->loadString($ref, $lang);
        if ($row === null) {
            return $this->error('not_found', 'Source not found.', 404);
        }

        return rest_ensure_response([
            'string'   => $this->presentString($row),
            'siblings' => $this->query->siblings($ref),
        ]);
    }

    public function duplicates(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $ref = $this->ref($request);
        if ($ref instanceof \WP_Error) {
            return $ref;
        }
        $lang = $this->targetLang($request);
        if ($lang instanceof \WP_Error) {
            return $lang;
        }

        return rest_ensure_response($this->query->duplicates($ref, $lang));
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

    // ── writes ──────────────────────────────────────────────────────────────

    public function putTranslation(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $ref = $this->ref($request);
        if ($ref instanceof \WP_Error) {
            return $ref;
        }
        $lang = $this->targetLang($request);
        if ($lang instanceof \WP_Error) {
            return $lang;
        }

        $row = $this->query->loadString($ref, $lang);
        if ($row === null) {
            return $this->error('not_found', 'Source not found.', 404);
        }

        $text = (string) $request->get_param('text');
        if (!$this->placeholdersPresent((string) $row['original_text'], $text)) {
            return $this->error('placeholder_mismatch', 'Formatting placeholders are missing from the translation.', 400);
        }

        $expected = $request->get_param('updated_at');
        if (is_string($expected) && $expected !== '') {
            $current = $this->repository->translationUpdatedAt($ref, $lang);
            if ($current !== null && $current !== $expected) {
                return $this->error('conflict', 'This translation was changed elsewhere.', 409, ['server' => $row]);
            }
        }

        $this->repository->saveTranslation($ref, $lang, $text, $this->markStatus((string) $request->get_param('mark')), null);

        $fresh = $this->query->loadString($ref, $lang);

        return rest_ensure_response(['string' => $fresh === null ? null : $this->presentString($fresh)]);
    }

    public function deleteTranslation(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $ref = $this->ref($request);
        if ($ref instanceof \WP_Error) {
            return $ref;
        }
        $lang = $this->targetLang($request);
        if ($lang instanceof \WP_Error) {
            return $lang;
        }

        $this->repository->deleteTranslation($ref, $lang);

        return rest_ensure_response(['ok' => true]);
    }

    public function putTranslations(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $ref = $this->ref($request);
        if ($ref instanceof \WP_Error) {
            return $ref;
        }

        $original = $this->query->loadString($ref, $this->languages->getDefaultLanguage());
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
            $this->repository->saveTranslation($ref, $lang, $text, TranslationStatus::NEEDS_REVIEW, null);
            $saved++;
        }

        return rest_ensure_response(['saved' => $saved]);
    }

    public function ignore(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $ref = $this->ref($request);
        if ($ref instanceof \WP_Error) {
            return $ref;
        }
        $this->repository->ignoreSource($ref);

        return rest_ensure_response(['ok' => true]);
    }

    public function restore(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $refs = [];
        foreach ((array) $request->get_param('keys') as $key) {
            $ref = SourceRef::parse((string) $key);
            if ($ref === null) {
                return $this->error('invalid_key', 'Malformed source ref.', 400);
            }
            $refs[] = $ref;
        }
        $this->repository->restoreSources($refs);

        return rest_ensure_response(['ok' => true, 'restored' => count($refs)]);
    }

    public function applyEverywhere(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $ref = $this->ref($request);
        if ($ref instanceof \WP_Error) {
            return $ref;
        }
        $lang = $this->targetLang($request);
        if ($lang instanceof \WP_Error) {
            return $lang;
        }

        $targets = [];
        foreach ((array) $request->get_param('targets') as $key) {
            $target = SourceRef::parse((string) $key);
            if ($target === null) {
                return $this->error('invalid_key', 'Malformed target ref.', 400);
            }
            $targets[] = $target;
        }

        // Applying a translation to its duplicates IS the confirmation, so the
        // copies land at the same status the user chose for the primary string
        // (default Reviewed/Done) — they do not get parked in "To review".
        $applied = $this->repository->applyTranslationToRefs(
            $targets,
            $lang,
            (string) $request->get_param('text'),
            $this->markStatus((string) $request->get_param('mark')),
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

        $result        = $this->scanner->scan();
        $optionInserted = $this->optionScanner->scan();

        return rest_ensure_response([
            'ok'       => true,
            'sources'  => $result->totalSourceStrings(),
            'inserted' => $result->totalInsertedRows() + $optionInserted,
            'skipped'  => $result->skipped,
        ]);
    }

    /**
     * Stream a CSV of every source + its translations: one row per source, one
     * column per language. Downloads directly (headers + exit), so the SPA must
     * fetch it with the nonce (apiFetch parse:false) and save the blob.
     */
    public function export(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $langs = $this->exportLangs($request);
        if ($langs instanceof \WP_Error) {
            return $langs;
        }

        $areaParam = (string) $request->get_param('area');
        $areaKeys  = ($areaParam === '' || $areaParam === 'all') ? AdminBoardQuery::areaKeys() : [$areaParam];

        $rows  = [];
        $order = [];
        foreach ($areaKeys as $areaKey) {
            $area = AdminBoardQuery::area($areaKey);
            if ($area === null) {
                continue;
            }
            foreach ($langs as $lang) {
                $cursor = null;
                do {
                    $res = $this->query->items([
                        'post_type'    => $area['post_type'],
                        'tables'       => $area['tables'],
                        'lang'         => $lang,
                        'tab'          => 'all',
                        'q_original'   => '',
                        'q_translated' => '',
                        'origin'       => 'all',
                        'cursor'       => $cursor,
                        'limit'        => 100,
                    ]);
                    foreach ($res['rows'] as $r) {
                        $key = (string) $r['source_key'];
                        if (!isset($rows[$key])) {
                            $rows[$key] = [
                                'kind'      => (string) $r['kind'],
                                'reference' => trim(((string) ($r['ref1'] ?? '')) . ' ' . ((string) ($r['ref2'] ?? ''))),
                                'original'  => (string) $r['original_text'],
                                'tr'        => [],
                            ];
                            $order[] = $key;
                        }
                        if ($r['translated_text'] !== null) {
                            $rows[$key]['tr'][$lang] = (string) $r['translated_text'];
                        }
                    }
                    $cursor = $res['next_cursor'];
                } while ($cursor !== null);
            }
        }

        // ponytail: loops items() once per language; add a dedicated multi-lang
        // export query only if export volume becomes a problem.
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="translangs-export.csv"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly.
        fputcsv($out, array_merge(['source_key', 'type', 'reference', 'original'], $langs));
        foreach ($order as $key) {
            $row  = $rows[$key];
            $line = [$key, $row['kind'], $row['reference'], $row['original']];
            foreach ($langs as $lang) {
                $line[] = $row['tr'][$lang] ?? '';
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    /**
     * Import a previously-exported CSV. With ?preview=1 it validates and counts
     * without writing; otherwise each non-empty language cell is saved as
     * needs_review (imported rows land in "To review"). Bad refs / unknown
     * sources are skipped; placeholder mismatches are reported, never fatal.
     */
    public function import(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $files = $request->get_file_params();
        $file  = $files['file'] ?? null;
        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return $this->error('no_file', 'No CSV file was uploaded.', 400);
        }

        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            return $this->error('unreadable', 'Could not read the uploaded file.', 400);
        }

        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            return $this->error('empty', 'The file is empty.', 400);
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]); // strip BOM

        $header  = array_map(static fn($h): string => trim((string) $h), $header);
        $keyCol  = array_search('source_key', $header, true);
        if ($keyCol === false) {
            fclose($handle);
            return $this->error('no_key', 'The file has no source_key column.', 400);
        }

        $targets  = $this->languages->getTargetLanguages();
        $langCols = [];
        foreach ($header as $i => $name) {
            if (in_array($name, $targets, true)) {
                $langCols[$i] = $name;
            }
        }

        $preview = (bool) $request->get_param('preview');
        $saved   = 0;
        $skipped = 0;
        $errors  = [];

        while (($line = fgetcsv($handle)) !== false) {
            $key = isset($line[$keyCol]) ? trim((string) $line[$keyCol]) : '';
            $ref = SourceRef::parse($key);
            if ($ref === null) {
                $skipped++;
                continue;
            }
            $original = $this->query->loadString($ref, $this->languages->getDefaultLanguage());
            if ($original === null) {
                $skipped++;
                continue;
            }
            $originalText = (string) $original['original_text'];

            foreach ($langCols as $i => $lang) {
                $text = isset($line[$i]) ? (string) $line[$i] : '';
                if (trim($text) === '') {
                    continue;
                }
                if (!$this->placeholdersPresent($originalText, $text)) {
                    $errors[] = "{$key} [{$lang}]: formatting placeholders missing";
                    continue;
                }
                if (!$preview) {
                    $this->repository->saveTranslation($ref, $lang, $text, TranslationStatus::NEEDS_REVIEW, null);
                }
                $saved++;
            }
        }
        fclose($handle);

        return rest_ensure_response([
            'ok'      => !$preview,
            'preview' => $preview,
            'saved'   => $saved,
            'skipped' => $skipped,
            'errors'  => $errors,
        ]);
    }

    /**
     * Validated, comma-separated target languages from the `langs` param; all
     * targets when empty.
     *
     * @return string[]|\WP_Error
     */
    private function exportLangs(\WP_REST_Request $request): array|\WP_Error
    {
        $targets = $this->languages->getTargetLanguages();
        $raw     = (string) $request->get_param('langs');
        if ($raw === '') {
            return $targets;
        }

        $langs = [];
        foreach (explode(',', $raw) as $code) {
            $code = trim($code);
            if ($code === '') {
                continue;
            }
            if (!in_array($code, $targets, true)) {
                return $this->error('invalid_lang', "Unknown or inactive target language '{$code}'.", 400);
            }
            $langs[] = $code;
        }

        return $langs === [] ? $targets : $langs;
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

    private function ref(\WP_REST_Request $request): SourceRef|\WP_Error
    {
        $ref = SourceRef::parse((string) $request->get_param('key'));

        return $ref ?? $this->error('invalid_key', 'Malformed source ref.', 400);
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

    /** Every {n}/{/n}/{n/} token in the original must be present in the text. */
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
            $kind = (string) $row['kind'];
            if ($kind === 'post' || $kind === 'meta') {
                $ownerKey = 'p' . (int) $row['post_id'];
            } elseif ($kind === 'term') {
                $ownerKey = 't' . (int) $row['term_taxonomy_id'];
            } else {
                $ownerKey = 's' . (int) $row['id']; // staticText/html: each string is its own card
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
                'origin'      => $row['translated_text'] === null
                    ? null
                    : ($row['provider'] !== null ? 'machine' : 'human'),
            ];
        }

        return array_map(static fn(string $k): array => $groups[$k], $order);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function ownerInfo(array $row): array
    {
        $kind = (string) $row['kind'];

        if (($kind === 'post' || $kind === 'meta') && function_exists('get_post')) {
            $postId = (int) $row['post_id'];
            $post   = get_post($postId);
            return [
                'id'    => $postId,
                'label' => $post instanceof \WP_Post ? $post->post_title : ('#' . $postId),
                'kind'  => $post instanceof \WP_Post ? $post->post_type : 'post',
                'thumb' => $post instanceof \WP_Post && $post->post_type === 'attachment'
                    ? $this->attachmentThumb($postId)
                    : null,
            ];
        }

        if ($kind === 'term' && function_exists('get_term_by')) {
            $term = get_term_by('term_taxonomy_id', (int) $row['term_taxonomy_id']);
            return [
                'id'    => (int) $row['term_taxonomy_id'],
                'label' => $term instanceof \WP_Term ? $term->name : ('#' . (int) $row['term_taxonomy_id']),
                'kind'  => $term instanceof \WP_Term ? $term->taxonomy : 'term',
            ];
        }

        return ['id' => (int) $row['id'], 'label' => (string) $row['ref1'], 'kind' => $kind];
    }

    /** A 64px preview URL for a media attachment: image thumbnail, else a file-type icon. */
    private function attachmentThumb(int $attachmentId): ?string
    {
        if (function_exists('wp_get_attachment_image_url')) {
            $url = wp_get_attachment_image_url($attachmentId, [64, 64]);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        if (function_exists('wp_mime_type_icon')) {
            $icon = wp_mime_type_icon($attachmentId);
            return is_string($icon) && $icon !== '' ? $icon : null;
        }

        return null;
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
            'kind'         => $row['kind'],
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

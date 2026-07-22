<?php

namespace TransLangs\Admin\Rest;

use TransLangs\Api\BackendClient;
use TransLangs\Api\SiteMetadataProvider;
use TransLangs\Core\Repository\SourceTranslationRepository;
use TransLangs\Core\Source\Value\SourceRef;
use TransLangs\Core\Source\Value\TranslationStatus;
use TransLangs\Language\Infrastructure\LanguageMetadataStore;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * AI translation + quota endpoints (namespace translangs/v1).
 *
 * v1 is SYNCHRONOUS: a request resolves the pending source units for its scope,
 * checks the cached quota, then translates in batches of 50 via the backend and
 * writes each result as MACHINE (status 1, provider "ai"). This keeps the code
 * readable and testable; large/language-wide jobs should move to an Action
 * Scheduler job (see arch-workflow-ai-translation.md) if request time becomes a
 * problem — the batching + save loop here transplants directly into a job worker.
 *
 * "AI translate all" targets missing + needs_review units (already-done ones are
 * skipped). Blocked cleanly (ERR_QUOTA) when the estimate exceeds remaining words.
 */
final class AiTranslationController
{
    private const NS          = 'translangs/v1';
    private const BATCH        = 50;
    private const PROVIDER      = 'ai';
    private const QUOTA_CACHE    = 'translangs_quota_cache';
    private const QUOTA_TTL       = 60;

    public function __construct(
        private BackendClient $backend,
        private SourceTranslationRepository $repo,
        private OptionLanguageRegistry $registry,
        private LanguageMetadataStore $langStore
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/translate', ['methods' => 'POST', 'callback' => [$this, 'translate']] + $guard);
        register_rest_route(self::NS, '/quota', ['methods' => 'GET', 'callback' => [$this, 'quota']] + $guard);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /** Translate a scope (text | collection | language) synchronously in batches. */
    public function translate(WP_REST_Request $request): WP_REST_Response
    {
        $lang  = $this->resolveLang($request);
        $scope = (string) $request->get_param('scope');

        if ($lang === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $units = $this->unitsForScope($scope, $request, $lang);
        if ($units === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        if ($units === []) {
            return rest_ensure_response(['ok' => true, 'translated' => 0, 'words_used' => 0]);
        }

        // Quota pre-check against the (cached) backend snapshot.
        $estimate = 0;
        foreach ($units as $u) {
            $estimate += str_word_count(wp_strip_all_tags($u['original']));
        }
        $quota = $this->quotaData();
        if ($quota !== null && $quota['words_remaining'] < $estimate) {
            return rest_ensure_response([
                'ok'              => false,
                'error'           => 'ERR_QUOTA',
                'estimated_words' => $estimate,
                'words_remaining' => $quota['words_remaining'],
            ]);
        }

        $source   = $this->registry->getDefaultLanguage();
        $aiStyle  = (string) ($this->langStore->get($lang)['ai_style'] ?? '');
        $cats     = array_values((array) get_option(SiteMetadataProvider::OPTION_CATEGORIES, []));
        $done     = 0;
        $words    = 0;

        foreach (array_chunk($units, self::BATCH) as $chunk) {
            $payload = [
                'source_language' => $source,
                'target_language' => $lang,
                'ai_style'        => $aiStyle,
                'categories'      => $cats,
                'texts'           => array_map(
                    static fn (array $u): array => ['id' => $u['ref']->format(), 'text' => $u['original']],
                    $chunk
                ),
            ];

            $res = $this->backend->translate($payload);
            if (! $res['ok']) {
                return rest_ensure_response([
                    'ok'         => false,
                    'error'      => $res['error'],
                    'translated' => $done,
                    'words_used' => $words,
                ]);
            }

            $data = is_array($res['data']) ? $res['data'] : [];
            $byId = [];
            foreach ((array) ($data['translations'] ?? []) as $t) {
                $byId[(string) ($t['id'] ?? '')] = (string) ($t['text'] ?? '');
            }

            foreach ($chunk as $u) {
                $text = $byId[$u['ref']->format()] ?? null;
                if ($text === null || $text === '') {
                    continue;
                }
                $this->repo->saveTranslation($u['ref'], $lang, $text, TranslationStatus::MACHINE, self::PROVIDER);
                $done++;
            }
            $words += (int) ($data['words_used'] ?? 0);
        }

        delete_transient(self::QUOTA_CACHE); // usage changed; force a fresh read next time.

        return rest_ensure_response(['ok' => true, 'translated' => $done, 'words_used' => $words]);
    }

    /** Current AI word quota snapshot (cached ~60s), proxied from the backend. */
    public function quota(WP_REST_Request $request): WP_REST_Response
    {
        $data = $this->quotaData();
        if ($data === null) {
            return rest_ensure_response(['ok' => false, 'error' => 'ERR_BACKEND']);
        }

        return rest_ensure_response(['ok' => true, 'quota' => $data]);
    }

    /** @return array{plan_name:string, word_limit:int, words_used:int, words_remaining:int, renewal_date:string}|null */
    private function quotaData(): ?array
    {
        $cached = get_transient(self::QUOTA_CACHE);
        if (is_array($cached)) {
            return $cached;
        }

        $res = $this->backend->usage();
        if (! $res['ok'] || ! is_array($res['data'])) {
            return null;
        }

        $d    = $res['data'];
        $data = [
            'plan_name'       => (string) ($d['plan_name'] ?? ''),
            'word_limit'      => (int) ($d['word_limit'] ?? 0),
            'words_used'      => (int) ($d['words_used'] ?? 0),
            'words_remaining' => (int) ($d['words_remaining'] ?? 0),
            'renewal_date'    => (string) ($d['renewal_date'] ?? ''),
        ];
        set_transient(self::QUOTA_CACHE, $data, self::QUOTA_TTL);

        return $data;
    }

    /**
     * Pending source units for a scope as [{ref, original, translated?, status?}].
     * 'text' translates the one ref regardless; 'collection'/'language' take only
     * missing + needs_review units. Returns null on a malformed scope/id.
     *
     * @return array<int, array{ref:SourceRef, original:string}>|null
     */
    private function unitsForScope(string $scope, WP_REST_Request $request, string $lang): ?array
    {
        if ($scope === 'text') {
            $ref = SourceRef::parse(urldecode((string) $request->get_param('ref')));
            if ($ref === null) {
                return null;
            }
            $text = $this->repo->loadSourceText($ref);
            return $text === null ? [] : [['ref' => $ref, 'original' => $text]];
        }

        if ($scope === 'collection') {
            $units = $this->collectionUnits(urldecode((string) $request->get_param('id')), $lang);
            return $units === null ? null : $this->pending($units);
        }

        if ($scope === 'language') {
            return $this->pending($this->languageUnits($lang));
        }

        return null;
    }

    /** Keep only untranslated + needs_review units (skip already-done). */
    private function pending(array $units): array
    {
        return array_values(array_filter(
            $units,
            static fn (array $u): bool =>
                ($u['translated'] ?? null) === null || ($u['status'] ?? null) === TranslationStatus::NEEDS_REVIEW
        ));
    }

    /**
     * Units of one collection id. ponytail: the id vocabulary is duplicated from
     * CollectionRestController — acceptable for two call sites; centralize if a
     * third appears.
     *
     * @return array<int, array{ref:SourceRef, original:string, translated:?string, status:?int}>|null
     */
    private function collectionUnits(string $id, string $lang): ?array
    {
        if ($id === 'option:site') {
            return $this->unitsFromRows('option', $this->repo->listOptionTexts($lang));
        }
        if ($id === 'html:all') {
            return $this->unitsFromRows('html', $this->repo->listHtmlTexts($lang));
        }
        if (preg_match('/^content:([0-9]+)$/', $id, $m)) {
            $pid = (int) $m[1];
            return array_merge(
                $this->unitsFromRows('post', $this->repo->listPostTexts($pid, $lang)),
                $this->unitsFromRows('meta', $this->repo->listMetaTexts($pid, $lang))
            );
        }
        if (preg_match('/^taxonomy:(.+)$/', $id, $m)) {
            return $this->unitsFromRows('term', $this->repo->listTaxonomyTexts($m[1], $lang));
        }

        return null;
    }

    /** Every content/setting/template unit for a language (posts, meta, terms, options, html). */
    private function languageUnits(string $lang): array
    {
        $units = array_merge(
            $this->unitsFromRows('option', $this->repo->listOptionTexts($lang)),
            $this->unitsFromRows('html', $this->repo->listHtmlTexts($lang))
        );
        foreach ($this->repo->listPostCollections($lang) as $c) {
            $pid   = $c['post_id'];
            $units = array_merge(
                $units,
                $this->unitsFromRows('post', $this->repo->listPostTexts($pid, $lang)),
                $this->unitsFromRows('meta', $this->repo->listMetaTexts($pid, $lang))
            );
        }
        foreach ($this->repo->listTermCollections($lang) as $t) {
            $units = array_merge($units, $this->unitsFromRows('term', $this->repo->listTermTexts($t['term_taxonomy_id'], $lang)));
        }

        return $units;
    }

    /** @return array<int, array{ref:SourceRef, original:string, translated:?string, status:?int}> */
    private function unitsFromRows(string $kind, array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'ref'        => SourceRef::make($kind, (int) $r['id']),
            'original'   => (string) $r['original_text'],
            'translated' => $r['translated_text'],
            'status'     => $r['status'],
        ], $rows);
    }

    private function resolveLang(WP_REST_Request $request): string
    {
        $lang = sanitize_key((string) $request->get_param('language'));
        if ($lang !== '') {
            return $lang;
        }

        return $this->registry->getTargetLanguages()[0] ?? '';
    }
}

<?php

namespace TransLangs\Admin\Rest;

use TransLangs\Language\Domain\LocaleNormalizer;

use TransLangs\Api\AiQuotaService;
use TransLangs\Api\BackendClient;
use TransLangs\Api\SiteMetadataProvider;
use TransLangs\Core\Parsing\TranslationSubmissionGuard;
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
    private const NS       = 'translangs/v1';
    private const BATCH     = 50;
    private const PROVIDER  = 'ai';

    public function __construct(
        private BackendClient $backend,
        private SourceTranslationRepository $repo,
        private OptionLanguageRegistry $registry,
        private LanguageMetadataStore $langStore,
        private CollectionRestController $collections,
        private AiQuotaService $quota
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
            return rest_ensure_response(['ok' => true, 'translated' => 0, 'words_used' => 0] + $this->summaryPatch($scope, $request, $lang));
        }

        // Quota pre-check against the (cached) backend snapshot.
        $estimate = 0;
        foreach ($units as $u) {
            $estimate += str_word_count(wp_strip_all_tags($u['original']));
        }
        $quota = $this->quota->data();
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
        $failures = [];

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

                // Validate from the in-memory unit — never a query per item.
                $errors = TranslationSubmissionGuard::validate(
                    (string) $u['original'],
                    $text,
                    $u['meta_key'] ?? null
                );
                if ($errors !== []) {
                    // Reject this item only: a mangled %%sep%% must never reach a
                    // rendered title, but 49 good translations must not be lost.
                    $failures[] = [
                        'ref'     => $u['ref']->format(),
                        'error'   => 'ERR_PROTECTED_TOKEN_STRUCTURE',
                        'details' => $errors,
                    ];
                    continue;
                }

                $this->repo->saveTranslation($u['ref'], $lang, $text, TranslationStatus::MACHINE, self::PROVIDER);
                $done++;
            }
            $words += (int) ($data['words_used'] ?? 0);
        }

        $this->quota->invalidate(); // usage changed; force a fresh read next time.

        // `failed`/`failures` are only present when something was rejected, so the
        // existing client shape is unchanged on a clean run.
        $rejected = $failures === []
            ? []
            : ['failed' => count($failures), 'failures' => $failures];

        return rest_ensure_response(
            ['ok' => true, 'translated' => $done, 'words_used' => $words]
            + $rejected
            + $this->summaryPatch($scope, $request, $lang)
        );
    }

    /**
     * For 'collection'/'text' scope, the updated summary of the one collection
     * involved — lets the caller patch the board's cache instead of refetching.
     * 'text' scope also resolves the single translated unit itself, so the
     * client can propagate it to any other visible occurrence of the same
     * text (hash-link kinds share one translation across positions). 'language'
     * scope touches every collection (and many units), so there's nothing
     * single to hand back; the caller falls back to invalidating.
     */
    private function summaryPatch(string $scope, WP_REST_Request $request, string $lang): array
    {
        if ($scope === 'text') {
            $collectionId = urldecode((string) $request->get_param('collection_id'));
            $ref          = SourceRef::parse(urldecode((string) $request->get_param('ref')));
            if ($collectionId === '' || $ref === null) {
                return [];
            }
            $result = $this->collections->unitAndSummaryFor($collectionId, $lang, $ref->format());
            $patch  = [];
            if ($result['summary'] !== null) {
                $patch['collection_summary'] = $result['summary'];
            }
            if ($result['unit'] !== null) {
                $patch['updated_unit'] = $result['unit'];
            }
            return $patch;
        }

        if ($scope === 'collection') {
            $collectionId = urldecode((string) $request->get_param('id'));
            if ($collectionId === '') {
                return [];
            }
            $summary = $this->collections->summaryFor($collectionId, $lang);
            return $summary === null ? [] : ['collection_summary' => $summary];
        }

        return [];
    }

    /** Current AI word quota snapshot (cached ~60s), proxied from the backend. */
    public function quota(WP_REST_Request $request): WP_REST_Response
    {
        $data = $this->quota->data();
        if ($data === null) {
            return rest_ensure_response(['ok' => false, 'error' => 'ERR_BACKEND']);
        }

        return rest_ensure_response(['ok' => true, 'quota' => $data]);
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
            $context = $this->repo->loadSourceValidationContext($ref);
            return $context === null
                ? []
                : [['ref' => $ref, 'original' => $context['text'], 'meta_key' => $context['meta_key']]];
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

    /**
     * Every unit for a language: posts/meta/terms/options/html — the sidebar's
     * per-language "Translate All" always uses this.
     */
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
        // meta rows already carry the key as `field` (listMetaTexts); keeping it on
        // the unit is what lets the write guard pick an SEO variable grammar without
        // a per-item query inside the result loop.
        return array_map(static fn (array $r): array => [
            'ref'        => SourceRef::make($kind, (int) $r['id']),
            'original'   => (string) $r['original_text'],
            'translated' => $r['translated_text'],
            'status'     => $r['status'],
            'meta_key'   => $kind === 'meta' ? (string) ($r['field'] ?? '') : null,
        ], $rows);
    }

    private function resolveLang(WP_REST_Request $request): string
    {
        $lang = LocaleNormalizer::normalize((string) $request->get_param('language'));
        if ($lang !== '') {
            return $lang;
        }

        return $this->registry->getTargetLanguages()[0] ?? '';
    }
}

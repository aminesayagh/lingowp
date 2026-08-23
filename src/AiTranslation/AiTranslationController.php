<?php

namespace TransLangs\AiTranslation;

use TransLangs\Language\Domain\LocaleNormalizer;
use TransLangs\Board\CollectionBoard;

use TransLangs\Backend\BillingEntitlementsService;
use TransLangs\Backend\ConnectionStatus;
use TransLangs\Database\Repository\WpDbSourceRepository;
use TransLangs\Shared\Source\SourceRef;
use TransLangs\Shared\Source\TranslationStatus;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * AI translation + quota endpoints (namespace translangs/v1).
 *
 * This is SYNCHRONOUS: a request resolves the pending source units for its scope,
 * then hands off to AiTranslationRunner, which batches via TranslationBatchPlanner
 * (the backend caps at 25 items/group_id, 100/request) and writes each result as
 * MACHINE (status 1, provider "ai"). This controller only does HTTP concerns —
 * param parsing and response shaping; large/language-wide jobs should move to an
 * Action Scheduler job (see arch-workflow-ai-translation.md) if request time
 * becomes a problem — the runner's batch/save loop transplants directly into a
 * job worker.
 *
 * There is no client-side quota pre-check here — whatever quota enforcement
 * exists belongs to /translations itself, not a separate gate in front of it.
 * GET /me/entitlements is the canonical snapshot for anyone who wants one
 * (the Dashboard's quota rail), not this controller.
 *
 * "AI translate all" targets missing + needs_review units (already-done ones
 * are skipped).
 */
final class AiTranslationController
{
    private const NS = 'translangs/v1';

    public function __construct(
        private WpDbSourceRepository $repo,
        private OptionLanguageRegistry $registry,
        private CollectionBoard $collections,
        private BillingEntitlementsService $entitlements,
        private AiTranslationRunner $runner,
        private ConnectionStatus $connectionStatus
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/translate', ['methods' => 'POST', 'callback' => [$this, 'translate']] + $guard);
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

        $result = $this->runner->run($units, $lang);
        if ($result['error'] !== null) {
            // A batch call to the backend itself failed (network/auth/backend
            // error) — record it now so the next status read reflects reality
            // immediately, instead of the SPA showing AI as available until
            // the next page load or health-check tick.
            $this->connectionStatus->markUnavailable($result['error']);

            return rest_ensure_response([
                'ok'         => false,
                'error'      => $result['error'],
                'translated' => $result['translated'],
                'words_used' => $result['words_used'],
            ]);
        }

        $this->entitlements->invalidate(); // usage changed; force a fresh read next time.

        // `failed`/`failures` are only present when something was rejected, so the
        // existing client shape is unchanged on a clean run.
        $rejected = $result['failures'] === []
            ? []
            : ['failed' => count($result['failures']), 'failures' => $result['failures']];

        return rest_ensure_response(
            ['ok' => true, 'translated' => $result['translated'], 'words_used' => $result['words_used']]
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

    /**
     * Pending source units for a scope as [{ref, original, group_id, type, translated?, status?}].
     * 'text' translates only the one clicked ref, regardless of its own status
     * (preserves "re-translate this one on demand") — never siblings from the
     * same post, so a single-text click stays a single-item request no matter
     * how large the surrounding post is. 'collection'/'language' take only
     * missing + needs_review units. Returns null on a malformed scope/id.
     *
     * @return array<int, array{ref:SourceRef, original:string, group_id:string, type:string}>|null
     */
    private function unitsForScope(string $scope, WP_REST_Request $request, string $lang): ?array
    {
        if ($scope === 'text') {
            $ref = SourceRef::parse(urldecode((string) $request->get_param('ref')));
            if ($ref === null) {
                return null;
            }
            $context = $this->repo->loadSourceValidationContext($ref);
            if ($context === null) {
                return [];
            }

            return [[
                'ref'      => $ref,
                'original' => $context['text'],
                'meta_key' => $context['meta_key'],
                'group_id' => $ref->format(),
                'type'     => $this->typeFor($context['kind']),
            ]];
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
     * CollectionBoard — acceptable for two call sites; centralize if a
     * third appears.
     *
     * @return array<int, array{ref:SourceRef, original:string, translated:?string, status:?int}>|null
     */
    private function collectionUnits(string $id, string $lang): ?array
    {
        if ($id === 'option:site') {
            return $this->unitsFromRows('option', $this->repo->listOptionTexts($lang), 'option:site');
        }
        if ($id === 'html:all') {
            return $this->unitsFromRows('html', $this->repo->listHtmlTexts($lang), $this->htmlGroupId());
        }
        if (preg_match('/^content:([0-9]+)$/', $id, $m)) {
            $pid = (int) $m[1];
            return array_merge(
                $this->unitsFromRows('post', $this->repo->listPostTexts($pid, $lang), "content:{$pid}"),
                $this->unitsFromRows('meta', $this->repo->listMetaTexts($pid, $lang), "content:{$pid}")
            );
        }
        if (preg_match('/^taxonomy:(.+)$/', $id, $m)) {
            return $this->unitsFromRows(
                'term',
                $this->repo->listTaxonomyTexts($m[1], $lang),
                static fn (array $r): string => 'term:' . (string) ($r['term_id'] ?? '')
            );
        }
        return null;
    }

    /** tl_html rows have no post link, so group by crawl source when known; a shared
     *  fallback bucket for url-less rows still gets sub-chunked correctly downstream. */
    private function htmlGroupId(): callable
    {
        return static fn (array $r): string =>
            isset($r['source_url']) && $r['source_url'] !== ''
                ? 'html:' . md5((string) $r['source_url'])
                : 'html:all';
    }

    /**
     * Every unit for a language: posts/meta/terms/options/html — the sidebar's
     * per-language "Translate All" always uses this.
     */
    private function languageUnits(string $lang): array
    {
        $units = array_merge(
            $this->unitsFromRows('option', $this->repo->listOptionTexts($lang), 'option:site'),
            $this->unitsFromRows('html', $this->repo->listHtmlTexts($lang), $this->htmlGroupId())
        );
        foreach ($this->repo->listPostCollections($lang) as $c) {
            $pid   = $c['post_id'];
            $units = array_merge(
                $units,
                $this->unitsFromRows('post', $this->repo->listPostTexts($pid, $lang), "content:{$pid}"),
                $this->unitsFromRows('meta', $this->repo->listMetaTexts($pid, $lang), "content:{$pid}")
            );
        }
        foreach ($this->repo->listTermCollections($lang) as $t) {
            $ttid  = $t['term_taxonomy_id'];
            $units = array_merge($units, $this->unitsFromRows('term', $this->repo->listTermTexts($ttid, $lang), "term:{$ttid}"));
        }

        return $units;
    }

    /**
     * @param string|callable(array $r):string $groupId constant string, or a
     *        per-row callable when the group varies per row (html: by source_url;
     *        term via taxonomy scope: by term_id)
     * @return array<int, array{ref:SourceRef, original:string, translated:?string, status:?int, meta_key:?string, group_id:string, type:string}>
     */
    private function unitsFromRows(string $kind, array $rows, $groupId): array
    {
        // meta rows already carry the key as `field` (listMetaTexts); keeping it on
        // the unit is what lets the write guard pick an SEO variable grammar without
        // a per-item query inside the result loop.
        return array_map(function (array $r) use ($kind, $groupId): array {
            return [
                'ref'        => SourceRef::make($kind, (int) $r['id']),
                'original'   => (string) $r['original_text'],
                'translated' => $r['translated_text'],
                'status'     => $r['status'],
                'meta_key'   => $kind === 'meta' ? (string) ($r['field'] ?? '') : null,
                'group_id'   => is_string($groupId) ? $groupId : $groupId($r),
                'type'       => $this->typeFor($kind),
            ];
        }, $rows);
    }

    /** tl_html rows are the only kind that are still raw, unencoded HTML blocks —
     *  everything else, including post_content, is a type-5 composed unit whose
     *  inline tags are already flattened into `{n}` markers by TranslationUnitCodec
     *  (see HtmlUnitExtractor). A composed unit must go through as plain text: the
     *  backend's text-path protection regex treats `{n}` as an opaque token, but
     *  its HTML path expects real `<tag>` markup and would either misparse the
     *  markers or, worse, let the model rewrite them. */
    private function typeFor(string $kind): string
    {
        return $kind === 'html' ? 'html' : 'text';
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

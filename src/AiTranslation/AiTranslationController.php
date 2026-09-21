<?php

namespace LingoWP\AiTranslation;

use LingoWP\Language\Domain\LocaleNormalizer;
use LingoWP\Board\CollectionBoard;

use LingoWP\Backend\BillingEntitlementsService;
use LingoWP\Backend\ConnectionStatus;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * AI translation + quota endpoints (namespace lingowp/v1).
 *
 * This is ASYNC: a request resolves the pending source units for its scope,
 * hands them to AiTaskSubmitter, which queues them on the backend and stamps
 * ai_pending_since -- then returns immediately. Nothing here writes a
 * translation; results land later via AiResultCollector (see
 * CollectionsRestController), pulled inline whenever the board is polled.
 * AI output is never marked done on its own -- it always lands as
 * needs_review (status -1, provider "ai"), a human still confirms it.
 *
 * A scope larger than AiQueueConfig::MAX_TEXTS_PER_SUBMIT is not chunked in
 * the background: this submits the first slice and reports `more`, and the
 * SPA re-POSTs the same request to continue -- no scheduler, no partial-
 * submit state to track.
 *
 * There is no client-side quota pre-check here — whatever quota enforcement
 * exists belongs to the backend's queue endpoint, not a separate gate in
 * front of it. GET /me/entitlements is the canonical snapshot for anyone who
 * wants one (the Dashboard's quota rail), not this controller.
 *
 * "AI translate all" targets only missing units -- anything already translated,
 * needs_review included, is left alone (see AiScopeUnits::pending()).
 */
final class AiTranslationController
{
    private const NS = 'lingowp/v1';

    private AiScopeUnits $units;
    private OptionLanguageRegistry $registry;
    private CollectionBoard $collections;
    private BillingEntitlementsService $entitlements;
    private AiTaskSubmitter $submitter;
    private ConnectionStatus $connectionStatus;

    public function __construct(
        AiScopeUnits $units,
        OptionLanguageRegistry $registry,
        CollectionBoard $collections,
        BillingEntitlementsService $entitlements,
        AiTaskSubmitter $submitter,
        ConnectionStatus $connectionStatus
    ) {
        $this->units            = $units;
        $this->registry         = $registry;
        $this->collections      = $collections;
        $this->entitlements     = $entitlements;
        $this->submitter        = $submitter;
        $this->connectionStatus = $connectionStatus;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NS, '/translate', ['methods' => 'POST', 'callback' => [$this, 'submit'], 'permission_callback' => [$this, 'canManage']]);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /** Queue a scope (text | collection | language) for async AI translation and return immediately. */
    public function submit(WP_REST_Request $request): WP_REST_Response
    {
        $scope = (string) $request->get_param('scope');
        $langs = $this->resolveLangs($request, $scope);

        if ($langs === []) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        // scope 'text' units are language-independent, so the primary is only used
        // for building the unit list and pinning the top-level summary keys.
        $primary = $langs[0];

        $units = $this->unitsForScope($scope, $request, $primary);
        if ($units === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        if ($units === []) {
            return rest_ensure_response(['ok' => true, 'submitted' => 0, 'more' => false] + $this->summaryPatch($scope, $request, $primary, $langs));
        }

        $total = count($units);
        $batch = array_slice($units, 0, AiQueueConfig::MAX_TEXTS_PER_SUBMIT);

        $result = $this->submitter->submit($batch, $langs);
        if ($result['error'] !== null) {
            // The submit call to the backend itself failed (network/auth/backend
            // error) — record it now so the next status read reflects reality
            // immediately, instead of the SPA showing AI as available until
            // the next page load or health-check tick.
            $this->connectionStatus->markUnavailable($result['error']);

            return rest_ensure_response(['ok' => false, 'error' => $result['error'], 'submitted' => 0]);
        }

        $this->entitlements->invalidate(); // usage changed; force a fresh read next time.

        return rest_ensure_response([
            'ok'        => true,
            'submitted' => count($batch),
            'more'      => $total > count($batch),
        ] + $this->summaryPatch($scope, $request, $primary, $langs));
    }

    /**
     * For 'collection'/'text' scope, the updated summary of the one collection
     * involved — lets the caller patch the board's cache instead of refetching.
     * 'text' scope also resolves the translated unit itself, so the client can
     * propagate it to any other visible occurrence of the same text (hash-link
     * kinds share one translation across positions); with more than one target
     * language it returns those per language under `by_language`, keeping the
     * top-level keys pinned to $lang for single-language callers. 'language'
     * scope touches every collection (and many units), so there's nothing
     * single to hand back; the caller falls back to invalidating.
     */
    private function summaryPatch(string $scope, WP_REST_Request $request, string $lang, array $langs): array
    {
        if ($scope === 'text') {
            $collectionId = urldecode((string) $request->get_param('collection_id'));
            $ref          = SourceRef::parse(urldecode((string) $request->get_param('ref')));
            if ($collectionId === '' || $ref === null) {
                return [];
            }
            $perLang = [];
            foreach ($langs as $l) {
                $result = $this->collections->unitAndSummaryFor($collectionId, $l, $ref->format());
                $entry  = [];
                if ($result['summary'] !== null) {
                    $entry['collection_summary'] = $result['summary'];
                }
                if ($result['unit'] !== null) {
                    $entry['updated_unit'] = $result['unit'];
                }
                $perLang[$l] = $entry;
            }
            // Top-level keys stay pinned to the primary language so single-language
            // callers (the row "Translate" button) get the exact shape they always
            // had; by_language is added only when more than one was requested.
            $patch = $perLang[$lang] ?? [];
            if (count($langs) > 1) {
                $patch['by_language'] = $perLang;
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
     * Parse the scope's request params, then hand off to AiScopeUnits. The kind
     * vocabulary and every DB read live there; this is just the HTTP half.
     */
    private function unitsForScope(string $scope, WP_REST_Request $request, string $lang): ?array
    {
        if ($scope === 'text') {
            $ref = SourceRef::parse(urldecode((string) $request->get_param('ref')));
            return $ref === null ? null : $this->units->forScope('text', $lang, '', $ref);
        }
        if ($scope === 'collection') {
            return $this->units->forScope('collection', $lang, urldecode((string) $request->get_param('id')));
        }

        return $this->units->forScope($scope, $lang);
    }

    private function resolveLang(WP_REST_Request $request): string
    {
        $lang = LocaleNormalizer::normalize((string) $request->get_param('language'));
        if ($lang !== '') {
            return $lang;
        }

        return $this->registry->getTargetLanguages()[0] ?? '';
    }

    /**
     * Target languages for this run. scope 'text' may carry a `languages` CSV —
     * CompareOverlay translates one string into every configured language in a
     * single request; every other scope stays single-language via `language`.
     *
     * @return string[] normalized, de-duplicated, order preserved
     */
    private function resolveLangs(WP_REST_Request $request, string $scope): array
    {
        if ($scope === 'text') {
            $raw = (string) $request->get_param('languages');
            if ($raw !== '') {
                $langs = array_values(array_unique(array_filter(
                    array_map(
                        static fn (string $l): string => LocaleNormalizer::normalize(trim($l)),
                        explode(',', $raw)
                    ),
                    static fn (string $l): bool => $l !== ''
                )));
                if ($langs !== []) {
                    return $langs;
                }
            }
        }

        $one = $this->resolveLang($request);

        return $one === '' ? [] : [$one];
    }
}

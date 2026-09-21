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

    public function submit(WP_REST_Request $request): WP_REST_Response
    {
        $scope = (string) $request->get_param('scope');
        $langs = $this->resolveLangs($request, $scope);

        if ($langs === []) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
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
            $this->connectionStatus->markUnavailable($result['error']);

            return rest_ensure_response(['ok' => false, 'error' => $result['error'], 'submitted' => 0]);
        }

        $this->entitlements->invalidate();

        return rest_ensure_response([
            'ok'        => true,
            'submitted' => count($batch),
            'more'      => $total > count($batch),
        ] + $this->summaryPatch($scope, $request, $primary, $langs));
    }

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

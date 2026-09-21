<?php

namespace LingoWP\GettextDomains;

use LingoWP\GettextDomains\InstalledGettextComponentDiscovery;
use LingoWP\AiTranslation\AiQueueConfig;
use LingoWP\Backend\BillingEntitlementsService;
use LingoWP\Backend\ConnectionStatus;
use LingoWP\GettextDomains\GettextDomainRegistry;
use LingoWP\GettextDomains\GettextOnlineTranslationFetcher;
use LingoWP\GettextDomains\GettextPoImporter;
use LingoWP\GettextDomains\GettextTranslationUnitsService;
use LingoWP\Language\Domain\LocaleNormalizer;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use WP_REST_Request;
use WP_REST_Response;

final class GettextDomainRestController
{
    private const NS = 'lingowp/v1';

    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 100;

    private const BOARD_TRANSLATE_SCAN_BUDGET = 5;

    private InstalledGettextComponentDiscovery $components;
    private GettextDomainRegistry $registry;
    private OptionLanguageRegistry $languageRegistry;
    private GettextOnlineTranslationFetcher $fetcher;
    private GettextPoImporter $importer;
    private GettextTranslationUnitsService $units;
    private BillingEntitlementsService $entitlements;
    private ConnectionStatus $connectionStatus;
    private GettextTranslatedPercentCache $percentCache;
    private GettextAiQueue $aiQueue;

    public function __construct(
        InstalledGettextComponentDiscovery $components,
        GettextDomainRegistry $registry,
        OptionLanguageRegistry $languageRegistry,
        GettextOnlineTranslationFetcher $fetcher,
        GettextPoImporter $importer,
        GettextTranslationUnitsService $units,
        BillingEntitlementsService $entitlements,
        ConnectionStatus $connectionStatus,
        GettextTranslatedPercentCache $percentCache,
        GettextAiQueue $aiQueue
    ) {
        $this->components       = $components;
        $this->registry         = $registry;
        $this->languageRegistry = $languageRegistry;
        $this->fetcher          = $fetcher;
        $this->importer         = $importer;
        $this->units            = $units;
        $this->entitlements     = $entitlements;
        $this->connectionStatus = $connectionStatus;
        $this->percentCache     = $percentCache;
        $this->aiQueue          = $aiQueue;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/gettext-domains', ['methods' => 'GET', 'callback' => [$this, 'list']] + $guard);
        register_rest_route(self::NS, '/gettext-domains/percent', ['methods' => 'GET', 'callback' => [$this, 'percent']] + $guard);
        register_rest_route(self::NS, '/gettext-domains/find-online', ['methods' => 'POST', 'callback' => [$this, 'findOnline']] + $guard);
        register_rest_route(self::NS, '/gettext-domains/import', ['methods' => 'POST', 'callback' => [$this, 'import']] + $guard);
        register_rest_route(self::NS, '/gettext-domains/download', ['methods' => 'GET', 'callback' => [$this, 'download']] + $guard);
        register_rest_route(self::NS, '/gettext-domains/units', ['methods' => 'GET', 'callback' => [$this, 'units']] + $guard);
        register_rest_route(self::NS, '/gettext-domains/scan', ['methods' => 'POST', 'callback' => [$this, 'scan']] + $guard);
        register_rest_route(self::NS, '/gettext-domains/translate', ['methods' => 'POST', 'callback' => [$this, 'translate']] + $guard);
        register_rest_route(self::NS, '/gettext-domains/refresh', ['methods' => 'POST', 'callback' => [$this, 'refresh']] + $guard);
        register_rest_route(self::NS, '/gettext-domains/save-unit', ['methods' => 'POST', 'callback' => [$this, 'saveUnit']] + $guard);
        register_rest_route(self::NS, '/gettext-domains/ignore-unit', ['methods' => 'POST', 'callback' => [$this, 'ignoreUnit']] + $guard);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $this->maybeCollectAiResults();

        $locale = LocaleNormalizer::normalize((string) $request->get_param('language'));
        if ($locale === '') {
            $locale = $this->languageRegistry->getTargetLanguages()[0] ?? '';
        }

        $offset = max(0, (int) $request->get_param('offset'));
        $limit  = min(self::MAX_LIMIT, max(1, (int) ($request->get_param('limit') ?: self::DEFAULT_LIMIT)));
        $search = trim((string) $request->get_param('search'));
        $untranslatedOnly = sanitize_key((string) $request->get_param('status')) === 'untranslated';

        $contentMatches = $search !== '' ? $this->units->domainsMatching($search) : [];
        $scannedKeys    = $search !== '' ? array_flip($this->units->scannedGroupKeys()) : [];

        $rows      = [];
        $unscanned = [];
        $scanTotal = 0;
        foreach ($this->components->all() as $domain => $component) {
            $groupKey = $component['kind'] . ':' . $domain;

            if ($search !== '' && $component['path'] !== '') {
                $scanTotal++;
                if (! isset($scannedKeys[$groupKey])) {
                    $unscanned[] = ['type' => $component['kind'], 'domain' => $domain];
                }
            }

            if ($search !== '' && ! isset($contentMatches[$groupKey])) {
                continue;
            }

            [$status, $sourceLocale] = $this->reconciledStatus($component['kind'], $domain, $locale);

            if ($untranslatedOnly && $status !== null) {
                $cached = $this->percentCache->get($component['kind'], $domain, $locale);
                if (($cached['percent'] ?? null) === 100) {
                    continue;
                }
            }

            $rows[] = [
                'domain'        => $domain,
                'label'         => $component['label'],
                'type'          => $component['kind'],
                'locale'        => $locale,
                'status'        => $status,
                'source_locale' => $sourceLocale,
            ];
        }

        $total    = count($rows);
        $page     = array_slice($rows, $offset, $limit);
        $returned = count($page);
        $next     = $offset + $returned;

        foreach ($page as &$row) {
            $cached = $this->percentCache->get($row['type'], $row['domain'], $row['locale']);
            $row['translated_percent'] = $cached['percent'] ?? null;

            $ai                = $this->aiQueue->pendingForList($row['type'], $row['domain'], $row['locale']);
            $row['ai_pending'] = count(array_filter($ai, static fn (array $a): bool => $a['pending']));
        }
        unset($row);

        return rest_ensure_response([
            'ok'          => true,
            'language'    => $locale,
            'rows'        => array_values($page),
            'total'       => $total,
            'offset'      => $offset,
            'limit'       => $limit,
            'returned'    => $returned,
            'next_offset' => $next,
            'has_more'    => $next < $total,
            'unscanned'   => $unscanned,
            'scan_total'  => $scanTotal,
        ]);
    }

    public function percent(WP_REST_Request $request): WP_REST_Response
    {
        [$type, $domain, $locale, $error] = $this->componentParams($request);
        if ($error !== null) {
            return $error;
        }

        $cached = $this->percentCache->get($type, $domain, $locale);
        if ($cached !== null) {
            return rest_ensure_response(['ok' => true, 'translated_percent' => $cached['percent']]);
        }

        $component = $this->components->all()[$domain] ?? null;
        $percent   = $component !== null
            ? $this->translatedPercent($component['path'], $type, $domain, $locale)
            : null;

        $this->percentCache->set($type, $domain, $locale, $percent);

        return rest_ensure_response(['ok' => true, 'translated_percent' => $percent]);
    }

    public function findOnline(WP_REST_Request $request): WP_REST_Response
    {
        [$type, $domain, $locale, $error] = $this->componentParams($request);
        if ($error !== null) {
            return $error;
        }

        $component = $this->components->all()[$domain] ?? null;
        if ($component === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_UNKNOWN_DOMAIN'], 400);
        }

        $result = $this->fetcher->fetch($type, $component['slug'], $domain, $component['version'], $locale);
        if ($result === null) {
            return rest_ensure_response(['ok' => true, 'found' => false]);
        }

        $sourceLocale = $result['own_region'] ? null : $result['fallback_locale'];
        $this->registry->set($type, $domain, $locale, GettextDomainRegistry::STATUS_FOUND_ONLINE, $sourceLocale);
        $this->units->recountTranslatedInFile($type, $domain, $locale);
        $this->percentCache->invalidate($type, $domain, $locale);

        return rest_ensure_response(['ok' => true, 'found' => true, 'source_locale' => $sourceLocale]);
    }

    public function import(WP_REST_Request $request): WP_REST_Response
    {
        [$type, $domain, $locale, $error] = $this->componentParams($request);
        if ($error !== null) {
            return $error;
        }

        $files = $request->get_file_params();
        $name  = (string) ($files['file']['name'] ?? '');
        $tmp   = (string) ($files['file']['tmp_name'] ?? '');

        if ($tmp === '' || strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'po') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        if (! $this->importer->import($tmp, $type, $domain, $locale)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_IMPORT_FAILED'], 400);
        }

        $this->registry->set($type, $domain, $locale, GettextDomainRegistry::STATUS_IMPORTED);
        $this->units->recountTranslatedInFile($type, $domain, $locale);
        $this->percentCache->invalidate($type, $domain, $locale);

        return rest_ensure_response(['ok' => true]);
    }

    public function download(WP_REST_Request $request): void
    {
        $type   = (string) $request->get_param('type');
        $domain = (string) $request->get_param('domain');
        $locale = (string) $request->get_param('locale');

        $path = $this->importer->targetPath($type, $domain, $locale);
        $po   = $this->importer->exportAsPo($path);
        if ($po === null) {
            status_header(404);
            wp_send_json(['ok' => false, 'error' => 'ERR_NOT_FOUND']);
            return;
        }

        nocache_headers();
        header('Content-Type: text/x-gettext-translation; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $domain . '-' . $locale . '.po"');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw .po file download (Content-Disposition: attachment), not HTML; escaping would corrupt the gettext PO format.
        echo $po->export();
        exit;
    }

    public function units(WP_REST_Request $request): WP_REST_Response
    {
        $this->maybeCollectAiResults();

        [$type, $domain, $locale, $error] = $this->componentParams($request);
        if ($error !== null) {
            return $error;
        }

        $search    = trim((string) $request->get_param('search'));
        $component = $this->components->all()[$domain] ?? null;
        $result    = $component !== null ? $this->units->list($component['path'], $type, $domain, $locale, $search) : null;

        if ($result === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_NO_SOURCE_TO_SCAN'], 400);
        }

        $ai = $this->aiQueue->pendingForList($type, $domain, $locale);
        foreach ($result as &$unit) {
            $uh          = GettextUnitRepository::hashHex($unit['msgid'], $unit['context']);
            $unit['ai_pending'] = $ai[$uh]['pending'] ?? false;
            $unit['ai_error']   = $ai[$uh]['error'] ?? null;
        }
        unset($unit);

        $status = sanitize_key((string) $request->get_param('status'));
        if ($status === 'untranslated') {
            $result = array_values(array_filter(
                $result,
                static fn (array $unit): bool => $unit['msgstr'] === ''
            ));
        }

        $offset = max(0, (int) $request->get_param('offset'));
        $limit  = min(self::MAX_LIMIT, max(1, (int) ($request->get_param('limit') ?: self::DEFAULT_LIMIT)));

        $total    = count($result);
        $page     = array_slice($result, $offset, $limit);
        $returned = count($page);
        $next     = $offset + $returned;

        return rest_ensure_response([
            'ok'          => true,
            'units'       => array_values($page),
            'total'       => $total,
            'offset'      => $offset,
            'limit'       => $limit,
            'returned'    => $returned,
            'next_offset' => $next,
            'has_more'    => $next < $total,
        ]);
    }

    public function saveUnit(WP_REST_Request $request): WP_REST_Response
    {
        [$type, $domain, $locale, $error] = $this->componentParams($request);
        if ($error !== null) {
            return $error;
        }

        [$msgid, $context] = $this->unitIdentity($request);
        $msgstr = (string) $request->get_param('msgstr');

        if ($msgid === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        if (! $this->units->saveUnit($type, $domain, $locale, $msgid, $context, $msgstr)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_SAVE_FAILED'], 400);
        }

        if ($this->registry->get($type, $domain, $locale) === null) {
            $this->registry->set($type, $domain, $locale, GettextDomainRegistry::STATUS_IMPORTED);
        }
        $this->percentCache->invalidate($type, $domain, $locale);

        return rest_ensure_response(['ok' => true]);
    }

    public function ignoreUnit(WP_REST_Request $request): WP_REST_Response
    {
        [$type, $domain, $locale, $error] = $this->componentParams($request);
        if ($error !== null) {
            return $error;
        }

        [$msgid, $context] = $this->unitIdentity($request);
        if ($msgid === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $this->units->ignoreUnit($type, $domain, $locale, $msgid, $context);

        foreach ($this->languageRegistry->getTargetLanguages() as $activeLocale) {
            $this->percentCache->invalidate($type, $domain, $activeLocale);
        }

        return rest_ensure_response(['ok' => true]);
    }

    private function reconciledStatus(string $type, string $domain, string $locale): array
    {
        $stored = $this->registry->get($type, $domain, $locale);
        $status = $stored['status'] ?? null;

        if ($status !== null && ! file_exists($this->importer->targetPath($type, $domain, $locale))) {
            return [null, null];
        }

        return [$status, $status !== null ? ($stored['source_locale'] ?? null) : null];
    }

    private function translatedPercent(string $path, string $type, string $domain, string $locale): ?int
    {
        if ($path === '') {
            return null;
        }

        $counts = $this->units->translatedCount($path, $type, $domain, $locale);
        if ($counts === null) {
            return null;
        }

        return (int) round($counts['translated'] / $counts['total'] * 100);
    }

    public function scan(WP_REST_Request $request): WP_REST_Response
    {
        $type   = (string) $request->get_param('type');
        $domain = (string) $request->get_param('domain');

        if (! in_array($type, ['plugin', 'theme'], true) || $domain === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $component = $this->components->all()[$domain] ?? null;
        if ($component === null || $component['kind'] !== $type) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $scanned = $this->units->ensureComponentScanned($component['path'], $type, $domain);

        return rest_ensure_response(['ok' => true, 'scanned' => $scanned]);
    }

    public function refresh(WP_REST_Request $request): WP_REST_Response
    {
        $type   = (string) $request->get_param('type');
        $domain = (string) $request->get_param('domain');

        if (! in_array($type, ['plugin', 'theme'], true) || $domain === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $component = $this->components->all()[$domain] ?? null;
        $result    = $component !== null ? $this->units->refresh($component['path'], $type, $domain) : null;

        if ($result === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_NO_SOURCE_TO_SCAN'], 400);
        }

        foreach ($this->languageRegistry->getTargetLanguages() as $activeLocale) {
            $this->percentCache->invalidate($type, $domain, $activeLocale);
        }

        return rest_ensure_response(['ok' => true] + $result);
    }

    public function translate(WP_REST_Request $request): WP_REST_Response
    {
        $scope  = (string) $request->get_param('scope');
        $locale = LocaleNormalizer::normalize((string) $request->get_param('locale'));
        if ($locale === '' || ! in_array($scope, ['unit', 'component', 'board'], true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        if ($scope === 'unit') {
            $type   = (string) $request->get_param('type');
            $domain = (string) $request->get_param('domain');
            [$msgid, $context] = $this->unitIdentity($request);
            $component = $this->components->all()[$domain] ?? null;
            if (! in_array($type, ['plugin', 'theme'], true) || $domain === '' || $msgid === ''
                || $component === null || $component['kind'] !== $type
            ) {
                return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
            }

            $this->units->ensureComponentScanned($component['path'], $type, $domain);
            $unitHash = GettextUnitRepository::hashHex($msgid, $context);
            if (! $this->aiQueue->hasActiveUnit($type, $domain, $unitHash)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
            }

            $batch = [[
                'type'      => $type,
                'domain'    => $domain,
                'msgid'     => $msgid,
                'context'   => $context,
                'unit_hash' => $unitHash,
            ]];
            $more = false;
        } elseif ($scope === 'component') {
            $type   = (string) $request->get_param('type');
            $domain = (string) $request->get_param('domain');
            $component = $this->components->all()[$domain] ?? null;
            if (! in_array($type, ['plugin', 'theme'], true) || $domain === ''
                || $component === null || $component['kind'] !== $type
            ) {
                return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
            }
            if (! $this->units->ensureComponentScanned($component['path'], $type, $domain)) {
                return rest_ensure_response(['ok' => true, 'submitted' => 0, 'more' => false]);
            }

            $units = $this->aiQueue->resolvePendingUnits($component['path'], $type, $domain, $locale);
            $more  = count($units) > AiQueueConfig::MAX_TEXTS_PER_SUBMIT;
            $batch = array_slice($units, 0, AiQueueConfig::MAX_TEXTS_PER_SUBMIT);
        } else {
            $scanBudget = self::BOARD_TRANSLATE_SCAN_BUDGET;
            $batch      = [];
            $more       = false;
            foreach ($this->components->all() as $domain => $component) {
                if ($component['path'] === '') {
                    continue;
                }
                if (! $this->units->wasComponentScanned($component['kind'], $domain)) {
                    if ($scanBudget <= 0) {
                        $more = true;
                        continue;
                    }
                    $scanBudget--;
                }
                if (! $this->units->ensureComponentScanned($component['path'], $component['kind'], $domain)) {
                    continue;
                }

                $remaining = AiQueueConfig::MAX_TEXTS_PER_SUBMIT - count($batch);
                if ($remaining <= 0) {
                    $more = true;
                    continue;
                }
                $pending = $this->aiQueue->resolvePendingUnits($component['path'], $component['kind'], $domain, $locale);
                if (count($pending) > $remaining) {
                    $more = true;
                }
                foreach (array_slice($pending, 0, $remaining) as $u) {
                    $batch[] = $u;
                }
            }
        }

        if ($batch === []) {
            return rest_ensure_response(['ok' => true, 'submitted' => 0, 'more' => $more]);
        }

        $result = $this->aiQueue->submit($batch, $locale);
        if ($result['error'] !== null) {
            $this->connectionStatus->markUnavailable($result['error']);

            return rest_ensure_response(['ok' => false, 'error' => $result['error'], 'submitted' => 0]);
        }

        $this->entitlements->invalidate();

        return rest_ensure_response(['ok' => true, 'submitted' => $result['submitted'], 'more' => $more]);
    }

    private function maybeCollectAiResults(): void
    {
        if (! $this->aiQueue->hasAnyPending()) {
            return;
        }
        if (! $this->aiQueue->tryPullLock()) {
            return;
        }
        try {
            $this->aiQueue->collectOnce();
        } finally {
            $this->aiQueue->releasePullLock();
        }
    }

    private function unitIdentity(WP_REST_Request $request): array
    {
        $msgid   = (string) $request->get_param('msgid');
        $context = $request->get_param('context');
        $context = $context !== null && $context !== '' ? (string) $context : null;

        return [$msgid, $context];
    }

    private function componentParams(WP_REST_Request $request): array
    {
        $type   = (string) $request->get_param('type');
        $domain = (string) $request->get_param('domain');
        $locale = (string) $request->get_param('locale');

        if (! in_array($type, ['plugin', 'theme'], true) || $domain === '' || $locale === '') {
            return ['', '', '', new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400)];
        }

        return [$type, $domain, $locale, null];
    }
}

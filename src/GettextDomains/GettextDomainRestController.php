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

/**
 * Gettext translation-file management (namespace lingowp/v1): for every
 * installed plugin/theme domain × active target locale, find an existing
 * wordpress.org translation, import one by hand, download the one currently
 * installed, or edit it directly in-app — one string at a time, the full
 * scanned list merged with whatever's already translated (see
 * GettextTranslationUnitsService).
 *
 * This is still file management, not a database: every save writes straight
 * into the domain+locale's `.mo`, the same file WordPress's own
 * `switch_to_locale()` (see GettextResolver) serves with no further
 * involvement from this plugin.
 */
final class GettextDomainRestController
{
    private const NS = 'lingowp/v1';

    /** Matches the Content board's own pagination shape (offset/limit/has_more). */
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 100;

    /** scope:'board' translate: at most this many never-scanned components pay
     * a full AST scan per request; the rest wait for the client's `more:true`
     * re-POST loop, so a big never-opened site doesn't block one request. */
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
        register_rest_route(self::NS, '/gettext-domains', ['methods' => 'GET', 'callback' => [$this, 'list'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/gettext-domains/percent', ['methods' => 'GET', 'callback' => [$this, 'percent'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/gettext-domains/find-online', ['methods' => 'POST', 'callback' => [$this, 'findOnline'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/gettext-domains/import', ['methods' => 'POST', 'callback' => [$this, 'import'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/gettext-domains/download', ['methods' => 'GET', 'callback' => [$this, 'download'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/gettext-domains/units', ['methods' => 'GET', 'callback' => [$this, 'units'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/gettext-domains/scan', ['methods' => 'POST', 'callback' => [$this, 'scan'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/gettext-domains/translate', ['methods' => 'POST', 'callback' => [$this, 'translate'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/gettext-domains/refresh', ['methods' => 'POST', 'callback' => [$this, 'refresh'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/gettext-domains/save-unit', ['methods' => 'POST', 'callback' => [$this, 'saveUnit'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/gettext-domains/ignore-unit', ['methods' => 'POST', 'callback' => [$this, 'ignoreUnit'], 'permission_callback' => [$this, 'canManage']]);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * One row per installed domain, scoped to a single working language —
     * same shape as CollectionsRestController::index(): a `language` param
     * (defaulting to the first active target), offset/limit pagination over
     * an already-assembled array, `has_more`/`next_offset` in the envelope.
     * Stored status is reconciled against the filesystem — a manually
     * deleted `.mo` self-heals back to "not found" instead of trusting
     * stale option data.
     */
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
        // Header "Untranslated only" toggle — mirrors the Content board's,
        // same ?status= vocabulary. On the domain list this means "hide
        // components already fully translated for this locale": a row is
        // kept when it has no `.mo` at all, or its (cached) percent is below
        // 100, or its percent isn't cached yet (unknown — the client's
        // percent backfill + its own guard settle those).
        $untranslatedOnly = sanitize_key((string) $request->get_param('status')) === 'untranslated';

        // Search matches ONLY a component's own translatable text (msgid), not
        // its name/slug. `domainsMatching()` only sees components already
        // scanned into the DB, so the client runs a progressive background
        // scan (POST /gettext-domains/scan per entry in `unscanned` below)
        // while a search is active. This endpoint itself never scans.
        $contentMatches = $search !== '' ? $this->units->domainsMatching($search) : [];
        $scannedKeys    = $search !== '' ? array_flip($this->units->scannedGroupKeys()) : [];

        $rows      = [];
        $unscanned = [];
        $scanTotal = 0;
        foreach ($this->components->all() as $domain => $component) {
            $groupKey = $component['kind'] . ':' . $domain;

            // Single-file plugins ($path === '') have no source tree to scan and
            // can never be markScanned() — leave them out of the scan bookkeeping
            // entirely or the client's "N/M" indicator never completes.
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

        // Cache-only: scanning a component's source (see translatedPercent())
        // is too expensive to do for every row on every list request. A row
        // shows a percent immediately if GET /gettext-domains/percent already
        // cached one for it; otherwise the client fetches that endpoint per
        // row in the background and patches it in once it lands.
        foreach ($page as &$row) {
            $cached = $this->percentCache->get($row['type'], $row['domain'], $row['locale']);
            $row['translated_percent'] = $cached['percent'] ?? null;

            // Same cache-only spirit as translated_percent above: one indexed
            // SELECT per row (ai_pending_since is indexed), no scan. Only the
            // pending count is surfaced — it drives the "Translating N…" chip
            // and the 3s poll; per-string failures show on the expanded rows.
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
            // Progressive-scan bookkeeping for the header search — empty / 0
            // when not searching (stable client contract).
            'unscanned'   => $unscanned,
            'scan_total'  => $scanTotal,
        ]);
    }

    /**
     * One domain+locale's translated percent, cached (see
     * GettextTranslatedPercentCache) — the client calls this once per row,
     * in the background, after GET /gettext-domains has already rendered
     * the list without waiting on any scan.
     */
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

        // Recorded as a fallback only when wordpress.org had no package for
        // this region at all (the whole file came from the default region). A
        // region that has its own package — even one merged with the default
        // region to fill gaps — stores no source_locale: the file is
        // primarily its own translation.
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

    /**
     * Streams the currently installed `.mo`, re-exported as `.po` for
     * editing — never returns a WP_REST_Response, same as any file download.
     */
    public function download(WP_REST_Request $request): void
    {
        $type   = (string) $request->get_param('type');
        $domain = (string) $request->get_param('domain');
        $locale = (string) $request->get_param('locale');

        $path = $this->importer->targetPath($type, $domain, $locale);
        $po   = $path === null ? null : $this->importer->exportAsPo($path);
        if ($po === null) {
            status_header(404);
            wp_send_json(['ok' => false, 'error' => 'ERR_NOT_FOUND']);
            return; // wp_send_json() already exits; keeps static analysis happy.
        }

        nocache_headers();
        header('Content-Type: text/x-gettext-translation; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $domain . '-' . $locale . '.po"');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw .po file download (Content-Disposition: attachment), not HTML; escaping would corrupt the gettext PO format.
        echo $po->export();
        exit;
    }

    /**
     * The editable row list for one domain+locale: every string the source
     * scan finds, each with its current translation (blank if none yet).
     * Works whether a `.mo` already exists or not — this is both "expand an
     * already-translated row" and "start translating this one manually".
     *
     * Paginated server-side, same offset/limit/has_more envelope as
     * GET /gettext-domains — the response only ever carries one page's worth
     * of strings, not the whole scanned list.
     */
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

        // Per-string AI pending/error, merged in for GettextUnitRow's shimmer +
        // persisted failure marker. Cheap: one indexed SELECT for the whole
        // component, keyed here by unit_hash.
        $ai = $this->aiQueue->pendingForList($type, $domain, $locale);
        foreach ($result as &$unit) {
            $uh          = GettextUnitRepository::hashHex($unit['msgid'], $unit['context']);
            $unit['ai_pending'] = $ai[$uh]['pending'] ?? false;
            $unit['ai_error']   = $ai[$uh]['error'] ?? null;
        }
        unset($unit);

        // Only filter this list supports today, same ?status= vocabulary as the
        // Content board (CollectionsRestController::resolveStatus()) though gettext
        // units have no needs_review concept -- msgstr is either empty or not.
        // Cheap regardless: $result is already the full scanned array at this point,
        // so this is one more array_filter over it before the slice below, not an
        // extra scan.
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

    /** Save one translated string, in place, into the domain+locale's `.mo`. */
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

        // Manual edits count as "imported" — but never downgrade a domain
        // that's already found_online/imported from a prior action.
        if ($this->registry->get($type, $domain, $locale) === null) {
            $this->registry->set($type, $domain, $locale, GettextDomainRegistry::STATUS_IMPORTED);
        }
        $this->percentCache->invalidate($type, $domain, $locale);

        return rest_ensure_response(['ok' => true]);
    }

    /**
     * Exclude one string from this domain+locale's editable list going
     * forward — the gettext equivalent of Content's "Ignore" status. There's
     * no row to flag, so this is a standing exclusion GettextTranslationUnitsService
     * checks on every scan, not a one-time delete.
     */
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

        // Ignoring is global per string now (see GettextUnitRepository), not
        // per locale, so it changes the active total for every target locale
        // of this domain at once — same reasoning as refresh()'s own loop
        // below, not just the one locale being viewed.
        foreach ($this->languageRegistry->getTargetLanguages() as $activeLocale) {
            $this->percentCache->invalidate($type, $domain, $activeLocale);
        }

        return rest_ensure_response(['ok' => true]);
    }

    /**
     * The stored status for one domain+locale, self-healed against the
     * filesystem: a manually-deleted `.mo` reverts to "not found" even if the
     * registry still remembers an earlier found/imported state.
     *
     * @param 'plugin'|'theme' $type
     * @return array{0: ?string, 1: ?string} [status, source_locale]
     */
    private function reconciledStatus(string $type, string $domain, string $locale): array
    {
        $stored = $this->registry->get($type, $domain, $locale);
        $status = $stored['status'] ?? null;

        $path = $this->importer->targetPath($type, $domain, $locale);
        if ($status !== null && ($path === null || ! file_exists($path))) {
            return [null, null];
        }

        return [$status, $status !== null ? ($stored['source_locale'] ?? null) : null];
    }

    /**
     * Null when there's no source to scan or nothing translatable in it — the
     * UI hides the badge then. Uses translatedCount() (a domain-wide SQL
     * count against GettextUnitRepository), not list() — this only needs a
     * ratio, not one page of units, and must not trigger list()'s pagination
     * assumptions.
     */
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

    /**
     * First-time source scan for one component, so its original strings
     * become searchable from the header search box. Driven by GettextBoard.jsx
     * one component at a time while a search is active (the `unscanned` list in
     * GET /gettext-domains). Idempotent — no-ops if already scanned. No
     * `locale` param: the string set is locale-independent. `scanned:false`
     * means a single-file plugin with no source tree.
     */
    public function scan(WP_REST_Request $request): WP_REST_Response
    {
        $type   = (string) $request->get_param('type');
        $domain = (string) $request->get_param('domain');

        if (! in_array($type, ['plugin', 'theme'], true) || $domain === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $component = $this->components->all()[$domain] ?? null;
        if ($component === null || $component['kind'] !== $type) {
            // kind mismatch: a plugin/theme sharing a text domain must not scan
            // the other's source tree into "$type:$domain" rows.
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $scanned = $this->units->ensureComponentScanned($component['path'], $type, $domain);

        return rest_ensure_response(['ok' => true, 'scanned' => $scanned]);
    }

    /**
     * Manual re-scan trigger (mirrors the Content board's own
     * POST /scan/refresh) — a plugin/theme update can add, remove, or reword
     * gettext calls in its source at any time, outside this plugin's
     * knowledge; there is no version-tracking to auto-detect that, so this is
     * the explicit "I know something changed, re-check it" action. No
     * `locale` param: the string set is locale-independent.
     */
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

        // A refresh changes the total string count for every locale of this
        // domain at once, not just one being viewed — unlike the other four
        // percentCache->invalidate() call sites, which each follow a
        // single-locale .mo write and only invalidate that one locale.
        foreach ($this->languageRegistry->getTargetLanguages() as $activeLocale) {
            $this->percentCache->invalidate($type, $domain, $activeLocale);
        }

        return rest_ensure_response(['ok' => true] + $result);
    }

    /**
     * The one AI-translate entry point for the Plugins & Themes board — every
     * scope goes through the same async queue (GettextAiQueue), exactly like
     * the Content board's own POST /translate. Body:
     * `{scope: 'unit'|'component'|'board', locale, type?, domain?, msgid?, context?}`.
     * Response `{ok, submitted, more}`; `more:true` means re-POST the identical
     * request to keep draining the backlog. Results are never in this
     * response — they land in the `.mo` on a later board read, when
     * maybeCollectAiResults() drains the backend queue. The row's own
     * `ai_pending` (from GET /units) drives the shimmer + 3s polling until
     * then.
     */
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

            // The unit's gettext_unit row MUST exist before we spend a backend
            // task on it — submit() stamps by unit_hash and collectOnce()
            // matches results by that row; if there's no row, the translation
            // comes back, matches nothing, and is acked away (quota burned,
            // nothing shown). Scan the component if it hasn't been, then
            // verify this exact string is active.
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
            // scope: 'board' — every installed component, current target
            // locale. Each never-scanned component costs a real recursive AST
            // scan, so at most SCAN_BUDGET of those run per request (same
            // "don't block one request on a whole-site scan" reasoning as the
            // header search's progressive scan) — the rest are left for the
            // client's `more:true` re-POST loop. Already-scanned components
            // are cheap (an indexed SELECT) and all run every call.
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

    /**
     * Drains the AI queue's backend result page on a read, same pattern as
     * CollectionsRestController::maybeCollectAiResults(): a cheap EXISTS probe
     * first, then a real MySQL lock so two concurrent board requests never
     * both pull+ack the same page.
     */
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

    /** @return array{0: string, 1: ?string} [msgid, context] */
    private function unitIdentity(WP_REST_Request $request): array
    {
        $msgid   = (string) $request->get_param('msgid');
        $context = $request->get_param('context');
        $context = $context !== null && $context !== '' ? (string) $context : null;

        return [$msgid, $context];
    }

    /**
     * Shared body-param validation for the POST actions.
     *
     * @return array{0: string, 1: string, 2: string, 3: WP_REST_Response|null}
     */
    private function componentParams(WP_REST_Request $request): array
    {
        $type   = (string) $request->get_param('type');
        $domain = (string) $request->get_param('domain');
        $locale = (string) $request->get_param('locale');

        // Same rule every .mo path is built with: a domain or locale that is
        // not a plain identifier is refused here, before anything runs.
        if ($this->importer->targetPath($type, $domain, $locale) === null) {
            return ['', '', '', new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400)];
        }

        return [$type, $domain, $locale, null];
    }
}

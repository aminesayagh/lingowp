<?php

namespace TransLangs\GettextDomains;

use TransLangs\GettextDomains\InstalledGettextComponentDiscovery;
use TransLangs\Backend\AiQuotaService;
use TransLangs\Backend\BackendClient;
use TransLangs\Backend\ConnectionStatus;
use TransLangs\GettextDomains\GettextDomainRegistry;
use TransLangs\GettextDomains\GettextOnlineTranslationFetcher;
use TransLangs\GettextDomains\GettextPoImporter;
use TransLangs\GettextDomains\GettextTranslationUnitsService;
use TransLangs\Language\Domain\LocaleNormalizer;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Gettext translation-file management (namespace translangs/v1): for every
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
    private const NS = 'translangs/v1';

    /** Matches the Content board's own pagination shape (offset/limit/has_more). */
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 100;

    public function __construct(
        private InstalledGettextComponentDiscovery $components,
        private GettextDomainRegistry $registry,
        private OptionLanguageRegistry $languageRegistry,
        private GettextOnlineTranslationFetcher $fetcher,
        private GettextPoImporter $importer,
        private GettextTranslationUnitsService $units,
        private BackendClient $backend,
        private AiQuotaService $quota,
        private ConnectionStatus $connectionStatus,
        private GettextTranslatedPercentCache $percentCache
    ) {}

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
        register_rest_route(self::NS, '/gettext-domains/save-unit', ['methods' => 'POST', 'callback' => [$this, 'saveUnit']] + $guard);
        register_rest_route(self::NS, '/gettext-domains/ignore-unit', ['methods' => 'POST', 'callback' => [$this, 'ignoreUnit']] + $guard);
        register_rest_route(self::NS, '/gettext-domains/translate-unit', ['methods' => 'POST', 'callback' => [$this, 'translateUnit']] + $guard);
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
        $locale = LocaleNormalizer::normalize((string) $request->get_param('language'));
        if ($locale === '') {
            $locale = $this->languageRegistry->getTargetLanguages()[0] ?? '';
        }

        $offset = max(0, (int) $request->get_param('offset'));
        $limit  = min(self::MAX_LIMIT, max(1, (int) ($request->get_param('limit') ?: self::DEFAULT_LIMIT)));
        $search = trim((string) $request->get_param('search'));

        $rows = [];
        foreach ($this->components->all() as $domain => $component) {
            if ($search !== '' && mb_stripos($component['label'], $search) === false && mb_stripos($domain, $search) === false) {
                continue;
            }

            [$status, $sourceLocale] = $this->reconciledStatus($component['kind'], $domain, $locale);

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

        $matchedLocale = $this->fetcher->fetch($type, $component['slug'], $component['version'], $locale);
        if ($matchedLocale === null) {
            return rest_ensure_response(['ok' => true, 'found' => false]);
        }

        // Only recorded as a fallback when it's actually a different locale
        // (fr_CA served from fr_FR) — a native match stores no source_locale.
        $sourceLocale = $matchedLocale !== $locale ? $matchedLocale : null;
        $this->registry->set($type, $domain, $locale, GettextDomainRegistry::STATUS_FOUND_ONLINE, $sourceLocale);

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
        $po   = $this->importer->exportAsPo($path);
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
        [$type, $domain, $locale, $error] = $this->componentParams($request);
        if ($error !== null) {
            return $error;
        }

        $component = $this->components->all()[$domain] ?? null;
        $result    = $component !== null ? $this->units->list($component['path'], $type, $domain, $locale) : null;

        if ($result === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_NO_SOURCE_TO_SCAN'], 400);
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
     * AI-translate one gettext string and save it, in place, same as
     * saveUnit() — the overlay's "Translate" button. One string at a time
     * rather than a batch, so a plugin/theme with 500 strings never blocks the
     * UI on a single all-or-nothing request. No client-side quota pre-check —
     * see AiTranslationController's docblock for why (GET /usage doesn't
     * return real numbers yet, so there's nothing to check against).
     */
    public function translateUnit(WP_REST_Request $request): WP_REST_Response
    {
        [$type, $domain, $locale, $error] = $this->componentParams($request);
        if ($error !== null) {
            return $error;
        }

        [$msgid, $context] = $this->unitIdentity($request);
        if ($msgid === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $payload = [
            'source_lang'  => $this->languageRegistry->getDefaultLanguage(),
            'target_langs' => [$locale],
            'texts'        => [[
                'text'     => $msgid,
                'group_id' => "{$type}:{$domain}",
                'type'     => 'text',
            ]],
        ];

        $res = $this->backend->translate($payload);
        if (! $res['ok']) {
            // Same connectivity-failure bookkeeping as AiTranslationController —
            // one shared "AI available" status regardless of which translate
            // entry point noticed the backend was down.
            $this->connectionStatus->markUnavailable($res['error']);

            return rest_ensure_response(['ok' => false, 'error' => $res['error']]);
        }

        $data = is_array($res['data']) ? $res['data'] : [];
        $text = '';
        foreach ((array) ($data['translations'] ?? []) as $t) {
            if (($t['text'] ?? '') === $msgid) {
                $text = (string) ($t['translation'] ?? '');
                break;
            }
        }
        if ($text === '') {
            $reason = 'ERR_EMPTY_RESULT';
            foreach ((array) ($data['failed'] ?? []) as $f) {
                if (($f['text'] ?? '') === $msgid) {
                    $reason = (string) ($f['reason'] ?? $reason);
                    break;
                }
            }
            return rest_ensure_response(['ok' => false, 'error' => $reason]);
        }

        if (! $this->units->saveUnit($type, $domain, $locale, $msgid, $context, $text)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_SAVE_FAILED'], 400);
        }

        if ($this->registry->get($type, $domain, $locale) === null) {
            $this->registry->set($type, $domain, $locale, GettextDomainRegistry::STATUS_IMPORTED);
        }
        $this->percentCache->invalidate($type, $domain, $locale);

        $this->quota->invalidate();

        return rest_ensure_response(['ok' => true, 'msgstr' => $text]);
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
        $this->percentCache->invalidate($type, $domain, $locale);

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

        if ($status !== null && ! file_exists($this->importer->targetPath($type, $domain, $locale))) {
            return [null, null];
        }

        return [$status, $status !== null ? ($stored['source_locale'] ?? null) : null];
    }

    /** Null when there's no source to scan or nothing translatable in it — the UI hides the badge then. */
    private function translatedPercent(string $path, string $type, string $domain, string $locale): ?int
    {
        if ($path === '') {
            return null;
        }

        $units = $this->units->list($path, $type, $domain, $locale);
        if ($units === null || $units === []) {
            return null;
        }

        $translated = 0;
        foreach ($units as $unit) {
            if ($unit['msgstr'] !== '') {
                $translated++;
            }
        }

        return (int) round($translated / count($units) * 100);
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

        if (! in_array($type, ['plugin', 'theme'], true) || $domain === '' || $locale === '') {
            return ['', '', '', new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400)];
        }

        return [$type, $domain, $locale, null];
    }
}

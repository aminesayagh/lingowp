<?php

namespace TransLangs\Admin\Rest;

use TransLangs\Admin\InstalledGettextComponentDiscovery;
use TransLangs\Api\AiQuotaService;
use TransLangs\Api\BackendClient;
use TransLangs\Api\SiteMetadataProvider;
use TransLangs\Language\Domain\LocaleNormalizer;
use TransLangs\Language\Gettext\GettextDomainRegistry;
use TransLangs\Language\Gettext\GettextOnlineTranslationFetcher;
use TransLangs\Language\Gettext\GettextPoImporter;
use TransLangs\Language\Gettext\GettextTranslationUnitsService;
use TransLangs\Language\Infrastructure\LanguageMetadataStore;
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
        private LanguageMetadataStore $langStore,
        private AiQuotaService $quota
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/gettext-domains', ['methods' => 'GET', 'callback' => [$this, 'list']] + $guard);
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
     * same shape as CollectionRestController::index(): a `language` param
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

        $rows = [];
        foreach ($this->components->all() as $domain => $component) {
            $stored = $this->registry->get($component['kind'], $domain, $locale);
            $status = $stored['status'] ?? null;

            if ($status !== null && ! file_exists($this->importer->targetPath($component['kind'], $domain, $locale))) {
                $status = null;
            }

            $rows[] = [
                'domain'        => $domain,
                'label'         => $component['label'],
                'type'          => $component['kind'],
                'locale'        => $locale,
                'status'        => $status,
                'source_locale' => $status !== null ? ($stored['source_locale'] ?? null) : null,
            ];
        }

        $total    = count($rows);
        $page     = array_slice($rows, $offset, $limit);
        $returned = count($page);
        $next     = $offset + $returned;

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
        if (! file_exists($path)) {
            status_header(404);
            wp_send_json(['ok' => false, 'error' => 'ERR_NOT_FOUND']);
            return; // wp_send_json() already exits; keeps static analysis happy.
        }

        if (! class_exists('MO')) {
            require_once ABSPATH . WPINC . '/pomo/mo.php';
        }
        if (! class_exists('PO')) {
            require_once ABSPATH . WPINC . '/pomo/po.php';
        }

        $mo = new \MO();
        $mo->import_from_file($path);

        $po          = new \PO();
        $po->headers = $mo->headers;
        foreach ($mo->entries as $entry) {
            $po->add_entry($entry);
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

        return rest_ensure_response(['ok' => true]);
    }

    /**
     * AI-translate one gettext string and save it, in place, same as
     * saveUnit() — the overlay's "Translate" button. Same quota gate as
     * Content's AiTranslationController::translate() (shared AiQuotaService),
     * one string at a time rather than a batch, so a plugin/theme with 500
     * strings never blocks the UI on a single all-or-nothing request.
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

        $estimate = str_word_count(wp_strip_all_tags($msgid));
        $quota    = $this->quota->data();
        if ($quota !== null && $quota['words_remaining'] < $estimate) {
            return rest_ensure_response([
                'ok'              => false,
                'error'           => 'ERR_QUOTA',
                'estimated_words' => $estimate,
                'words_remaining' => $quota['words_remaining'],
            ]);
        }

        $payload = [
            'source_language' => $this->languageRegistry->getDefaultLanguage(),
            'target_language' => $locale,
            'ai_style'        => (string) ($this->langStore->get($locale)['ai_style'] ?? ''),
            'categories'      => array_values((array) get_option(SiteMetadataProvider::OPTION_CATEGORIES, [])),
            'texts'           => [['id' => 'unit', 'text' => $msgid]],
        ];

        $res = $this->backend->translate($payload);
        if (! $res['ok']) {
            return rest_ensure_response(['ok' => false, 'error' => $res['error']]);
        }

        $data = is_array($res['data']) ? $res['data'] : [];
        $text = '';
        foreach ((array) ($data['translations'] ?? []) as $t) {
            if (($t['id'] ?? '') === 'unit') {
                $text = (string) ($t['text'] ?? '');
                break;
            }
        }
        if ($text === '') {
            return rest_ensure_response(['ok' => false, 'error' => 'ERR_EMPTY_RESULT']);
        }

        if (! $this->units->saveUnit($type, $domain, $locale, $msgid, $context, $text)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_SAVE_FAILED'], 400);
        }

        if ($this->registry->get($type, $domain, $locale) === null) {
            $this->registry->set($type, $domain, $locale, GettextDomainRegistry::STATUS_IMPORTED);
        }

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

        return rest_ensure_response(['ok' => true]);
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

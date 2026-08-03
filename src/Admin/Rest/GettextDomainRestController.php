<?php

namespace TransLangs\Admin\Rest;

use TransLangs\Admin\InstalledGettextComponentDiscovery;
use TransLangs\Language\Gettext\GettextDomainRegistry;
use TransLangs\Language\Gettext\GettextOnlineTranslationFetcher;
use TransLangs\Language\Gettext\GettextPoImporter;
use TransLangs\Language\Gettext\GettextTemplateGenerator;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Gettext translation-file management (namespace translangs/v1): for every
 * installed plugin/theme domain × active target locale, find an existing
 * wordpress.org translation, import one by hand, download the one currently
 * installed, or — only when neither produced anything — generate a `.pot`
 * template from that component's own source.
 *
 * This is file management, not an in-app string editor: once a `.mo` is in
 * place, WordPress's own `switch_to_locale()` (see GettextResolver) serves it
 * with no further involvement from this plugin.
 */
final class GettextDomainRestController
{
    private const NS = 'translangs/v1';

    public function __construct(
        private InstalledGettextComponentDiscovery $components,
        private GettextDomainRegistry $registry,
        private OptionLanguageRegistry $languageRegistry,
        private GettextOnlineTranslationFetcher $fetcher,
        private GettextPoImporter $importer,
        private GettextTemplateGenerator $generator
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
        register_rest_route(self::NS, '/gettext-domains/generate-pot', ['methods' => 'POST', 'callback' => [$this, 'generatePot']] + $guard);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * One row per (installed domain × active target locale). Stored status
     * is reconciled against the filesystem — a manually deleted `.mo` self-
     * heals back to "not found" instead of trusting stale option data.
     */
    public function list(): WP_REST_Response
    {
        $locales = $this->languageRegistry->getTargetLanguages();
        $rows    = [];

        foreach ($this->components->all() as $domain => $component) {
            foreach ($locales as $locale) {
                $stored = $this->registry->get($component['kind'], $domain, $locale);
                $status = $stored['status'] ?? null;

                if ($status !== null && ! file_exists($this->importer->targetPath($component['kind'], $domain, $locale))) {
                    $status = null;
                }

                $rows[] = [
                    'domain' => $domain,
                    'label'  => $component['label'],
                    'type'   => $component['kind'],
                    'locale' => $locale,
                    'status' => $status,
                ];
            }
        }

        return rest_ensure_response(['ok' => true, 'rows' => $rows]);
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

        $found = $this->fetcher->fetch($type, $component['slug'], $component['version'], $locale);
        if (! $found) {
            return rest_ensure_response(['ok' => true, 'found' => false]);
        }

        $this->registry->set($type, $domain, $locale, GettextDomainRegistry::STATUS_FOUND_ONLINE);

        return rest_ensure_response(['ok' => true, 'found' => true]);
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
        echo $po->export();
        exit;
    }

    /**
     * Streams a generated `.pot` — bypasses the JSON envelope, same as any
     * file download. Not locale-specific: marks every active target locale
     * for this domain as "template available" since there's one template
     * per component, not per locale.
     */
    public function generatePot(WP_REST_Request $request): void
    {
        $type   = (string) $request->get_param('type');
        $domain = (string) $request->get_param('domain');

        $component = $this->components->all()[$domain] ?? null;
        $result    = $component !== null ? $this->generator->generate($component['path'], $domain) : null;

        if ($result === null) {
            status_header(400);
            wp_send_json(['ok' => false, 'error' => 'ERR_NO_SOURCE_TO_SCAN']);
            return;
        }

        foreach ($this->languageRegistry->getTargetLanguages() as $locale) {
            $this->registry->set($type, $domain, $locale, GettextDomainRegistry::STATUS_TEMPLATE_PENDING);
        }

        nocache_headers();
        header('Content-Type: text/x-gettext-translation; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $domain . '.pot"');
        readfile($result['pot_path']);
        unlink($result['pot_path']);
        exit;
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

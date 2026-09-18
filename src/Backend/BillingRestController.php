<?php

namespace LingoWP\Backend;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Billing REST endpoints (namespace lingowp/v1).
 *
 * Pure pass-through to the backend's /billing/* and /me/entitlements routes.
 * manage_options only. subscribe()/changePlan()/orderPack()/captureOrder()
 * deliberately do NOT invalidate the entitlements cache — none of them
 * confirm anything by themselves (approve_url/order_id only, nothing local
 * has changed yet); the frontend's poll-until-settled loop always asks for a
 * fresh read explicitly via ?fresh=1 instead.
 */
final class BillingRestController
{
    private const NS = 'lingowp/v1';

    private BackendClient $backendClient;
    private BillingEntitlementsService $entitlements;
    private ConnectionRepository $connection;
    private AddonSiteMatcher $addonMatcher;

    public function __construct(
        BackendClient $backendClient,
        BillingEntitlementsService $entitlements,
        ConnectionRepository $connection,
        AddonSiteMatcher $addonMatcher
    ) {
        $this->backendClient = $backendClient;
        $this->entitlements  = $entitlements;
        $this->connection    = $connection;
        $this->addonMatcher  = $addonMatcher;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/billing/plans', ['methods' => 'GET', 'callback' => [$this, 'plans']] + $guard);
        register_rest_route(self::NS, '/billing/packs', ['methods' => 'GET', 'callback' => [$this, 'packs']] + $guard);
        register_rest_route(self::NS, '/billing/addons', ['methods' => 'GET', 'callback' => [$this, 'addons']] + $guard);
        register_rest_route(
            self::NS,
            '/billing/addons/(?P<addon_id>[^/]+)/install',
            ['methods' => 'POST', 'callback' => [$this, 'installAddon']] + $guard
        );
        register_rest_route(
            self::NS,
            '/billing/addons/coming-soon/(?P<slug>[^/]+)/notify',
            ['methods' => 'POST', 'callback' => [$this, 'notifyComingSoon']] + $guard
        );
        register_rest_route(self::NS, '/billing/entitlements', ['methods' => 'GET', 'callback' => [$this, 'entitlements']] + $guard);
        register_rest_route(self::NS, '/billing/transactions', ['methods' => 'GET', 'callback' => [$this, 'transactions']] + $guard);
        register_rest_route(self::NS, '/billing/usage', ['methods' => 'GET', 'callback' => [$this, 'usage']] + $guard);
        register_rest_route(self::NS, '/billing/subscription', ['methods' => 'POST', 'callback' => [$this, 'subscribe']] + $guard);
        register_rest_route(self::NS, '/billing/subscription/plan', ['methods' => 'POST', 'callback' => [$this, 'changePlan']] + $guard);
        register_rest_route(self::NS, '/billing/packs/order', ['methods' => 'POST', 'callback' => [$this, 'orderPack']] + $guard);
        register_rest_route(
            self::NS,
            '/billing/packs/order/(?P<order_id>[^/]+)/capture',
            ['methods' => 'POST', 'callback' => [$this, 'captureOrder']] + $guard
        );
        register_rest_route(self::NS, '/billing/owner', ['methods' => 'GET', 'callback' => [$this, 'ownerStatus']] + $guard);
        register_rest_route(self::NS, '/billing/owner', ['methods' => 'POST', 'callback' => [$this, 'assignOwner']] + $guard);
        register_rest_route(self::NS, '/billing/websites', ['methods' => 'GET', 'callback' => [$this, 'listWebsites']] + $guard);
        register_rest_route(self::NS, '/billing/websites', ['methods' => 'POST', 'callback' => [$this, 'addWebsite']] + $guard);
        register_rest_route(
            self::NS,
            '/billing/websites/(?P<uuid>[^/]+)',
            ['methods' => 'DELETE', 'callback' => [$this, 'deleteWebsite']] + $guard
        );
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function plans(): WP_REST_Response
    {
        $result = $this->backendClient->getPlans();

        return rest_ensure_response(['ok' => $result['ok'], 'plans' => $result['data']['plans'] ?? [], 'error' => $result['error']]);
    }

    public function packs(): WP_REST_Response
    {
        $result = $this->backendClient->getPacks();

        return rest_ensure_response(['ok' => $result['ok'], 'packs' => $result['data']['packs'] ?? [], 'error' => $result['error']]);
    }

    /**
     * ?lang= falls back to this site's admin locale (base language, e.g.
     * 'fr-CA' -> 'fr'). The catalog is tier-based: `tier` (top-level) is the
     * licence's plan, each add-on carries `tier` (lowest plan including it)
     * and `included`. This site adds the two facts only it can know —
     * `relevant` (its `detects` list hits an active plugin/theme here) and
     * `installed` (the lingowp-<product> plugin is active here) — plus
     * `recently_activated`, the read-once list of third-party activations
     * since the last LingoWP screen load, which drives the one-shot toast.
     * `coming_soon` is deliberately not forwarded: migration add-ons are
     * out of scope until their delivery is designed.
     */
    public function addons(WP_REST_Request $request): WP_REST_Response
    {
        $lang = sanitize_text_field((string) $request->get_param('lang'));
        if ($lang === '') {
            $lang = substr(get_locale(), 0, 2);
        }

        $result = $this->backendClient->getAddons($lang);

        $addons = [];
        foreach ((array) ($result['data']['addons'] ?? []) as $addon) {
            $addon['relevant']  = $this->addonMatcher->isRelevant((array) ($addon['detects'] ?? []));
            $addon['installed'] = $this->addonMatcher->isInstalled((string) ($addon['product'] ?? ''));
            $addons[]           = $addon;
        }

        return rest_ensure_response([
            'ok'                 => $result['ok'],
            'tier'               => $result['data']['tier'] ?? null,
            'categories'         => $result['data']['categories'] ?? [],
            'addons'             => $addons,
            'recently_activated' => $result['ok'] ? $this->addonMatcher->takePendingActivations() : [],
            'error'              => $result['error'],
        ]);
    }

    /**
     * Downloads the add-on zip and installs+activates it directly on this
     * site ("Install now" for an add-on the licence tier includes). The
     * backend is the gate — 402 upgrade_required when the tier is too low.
     * `product` comes from the request body rather than re-resolving the
     * catalog server-side — the frontend already has the full addon object
     * from GET /billing/addons.
     */
    public function installAddon(WP_REST_Request $request): WP_REST_Response
    {
        $addonId = sanitize_text_field((string) $request->get_param('addon_id'));
        $product = sanitize_text_field((string) $request->get_param('product'));
        if ($addonId === '' || $product === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_PAYLOAD'], 200);
        }

        $download = $this->backendClient->downloadAddonZip($addonId);
        if (! $download['ok']) {
            return rest_ensure_response(['ok' => false, 'error' => $download['error']]);
        }

        $result = (new AddonInstaller())->install((string) $download['data'], $product);

        return rest_ensure_response(['ok' => $result['ok'], 'error' => $result['error']]);
    }

    /**
     * A coming-soon card's "Notify me" — mirrors InsightsRestController::notify()'s
     * pattern exactly: no dedicated notify endpoint, just the existing
     * support-suggestion route with a fixed machine-readable message per slug.
     * No local persistence; a page reload always shows the button's default state.
     */
    public function notifyComingSoon(WP_REST_Request $request): WP_REST_Response
    {
        if (! $this->connection->isConnected()) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_NOT_CONNECTED'], 200);
        }

        $slug = sanitize_text_field((string) $request->get_param('slug'));
        if ($slug === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_PAYLOAD'], 200);
        }

        $result = $this->backendClient->submitSupportSuggestion("feature_interest:addon_{$slug}");

        return rest_ensure_response(['ok' => $result['ok'], 'error' => $result['error']]);
    }

    public function entitlements(WP_REST_Request $request): WP_REST_Response
    {
        $fresh = (bool) $request->get_param('fresh');
        $data  = $this->entitlements->data($fresh);

        return rest_ensure_response(['ok' => $data !== null, 'entitlements' => $data, 'error' => $data !== null ? '' : 'ERR_BACKEND']);
    }

    public function transactions(WP_REST_Request $request): WP_REST_Response
    {
        $page     = max(1, (int) $request->get_param('page'));
        $pageSize = min(50, max(1, (int) ($request->get_param('page_size') ?: 20)));
        $result   = $this->backendClient->getTransactions($page, $pageSize);

        return rest_ensure_response([
            'ok'           => $result['ok'],
            'transactions' => $result['data']['transactions'] ?? [],
            'page'         => $result['data']['page'] ?? $page,
            'page_size'    => $result['data']['page_size'] ?? $pageSize,
            'total'        => $result['data']['total'] ?? 0,
            'has_more'     => $result['data']['has_more'] ?? false,
            'error'        => $result['error'],
        ]);
    }

    public function usage(): WP_REST_Response
    {
        $result = $this->backendClient->getUsageStats();

        return rest_ensure_response([
            'ok'               => $result['ok'],
            'words_translated' => $result['data']['words_translated'] ?? 0,
            'daily'            => $result['data']['daily'] ?? [],
            'error'            => $result['error'],
        ]);
    }

    public function subscribe(WP_REST_Request $request): WP_REST_Response
    {
        $planKey = sanitize_text_field((string) $request->get_param('plan_key'));
        if ($planKey === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_PAYLOAD'], 200);
        }

        $result = $this->backendClient->subscribe($planKey);

        return rest_ensure_response(['ok' => $result['ok'], 'approve_url' => $result['data']['approve_url'] ?? null, 'error' => $result['error']]);
    }

    public function changePlan(WP_REST_Request $request): WP_REST_Response
    {
        $planKey = sanitize_text_field((string) $request->get_param('plan_key'));
        if ($planKey === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_PAYLOAD'], 200);
        }

        $result = $this->backendClient->changePlan($planKey);

        return rest_ensure_response(['ok' => $result['ok'], 'approve_url' => $result['data']['approve_url'] ?? null, 'error' => $result['error']]);
    }

    public function orderPack(WP_REST_Request $request): WP_REST_Response
    {
        $packId = sanitize_text_field((string) $request->get_param('pack_id'));
        if ($packId === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_PAYLOAD'], 200);
        }

        $result = $this->backendClient->orderPack($packId);

        return rest_ensure_response([
            'ok'          => $result['ok'],
            'order_id'    => $result['data']['order_id'] ?? null,
            'approve_url' => $result['data']['approve_url'] ?? null,
            'error'       => $result['error'],
        ]);
    }

    public function captureOrder(WP_REST_Request $request): WP_REST_Response
    {
        $orderId = sanitize_text_field((string) $request->get_param('order_id'));
        $result  = $this->backendClient->capturePackOrder($orderId);

        return rest_ensure_response(['ok' => $result['ok'], 'status' => $result['data']['status'] ?? null, 'error' => $result['error']]);
    }

    /**
     * There is no backend read for the licence's owner_email — only the write
     * (assignOwner) and its confirm callback, which lands through the shared
     * /connection/confirm route (SiteConnector::confirm()) since the SPA has
     * no way to know in advance which kind of token a confirm_token deep link
     * carries. This is a pure local read of whatever this site last learned
     * via a successful confirm (see ConnectionRepository::storeVerifiedOwnerEmail());
     * it is never "not yet verified but known", only unset or verified.
     */
    public function ownerStatus(): WP_REST_Response
    {
        return rest_ensure_response([
            'ok'          => true,
            'owner_email' => $this->connection->ownerEmail(),
            'verified'    => $this->connection->isOwnerEmailVerified(),
        ]);
    }

    /** Kicks off the assign-owner ask; nothing local changes until the emailed link is confirmed. */
    public function assignOwner(WP_REST_Request $request): WP_REST_Response
    {
        $email = sanitize_email((string) $request->get_param('email'));
        if ($email === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_PAYLOAD'], 200);
        }

        $result = $this->backendClient->assignOwner($email);

        return rest_ensure_response(['ok' => $result['ok'], 'error' => $result['error']]);
    }

    /** Every confirmed site under this license (pending, unconfirmed adds don't appear here). */
    public function listWebsites(): WP_REST_Response
    {
        $result = $this->backendClient->listWebsites();

        return rest_ensure_response([
            'ok'       => $result['ok'],
            'websites' => $result['data']['websites'] ?? [],
            'error'    => $result['error'],
        ]);
    }

    /** Kicks off the add-website ask; the new site doesn't exist until its own confirm link is clicked. */
    public function addWebsite(WP_REST_Request $request): WP_REST_Response
    {
        $domain     = sanitize_text_field((string) $request->get_param('domain'));
        $adminEmail = sanitize_email((string) $request->get_param('admin_email'));

        if ($domain === '' || $adminEmail === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_PAYLOAD'], 200);
        }

        $result = $this->backendClient->addWebsite($domain, $adminEmail);

        return rest_ensure_response(['ok' => $result['ok'], 'sent' => (bool) ($result['data']['sent'] ?? $result['ok']), 'error' => $result['error']]);
    }

    /** Immediate, synchronous hard delete — no confirmation email, no undo. */
    public function deleteWebsite(WP_REST_Request $request): WP_REST_Response
    {
        $uuid = sanitize_text_field((string) $request->get_param('uuid'));

        $result = $this->backendClient->deleteWebsite($uuid);

        return rest_ensure_response(['ok' => $result['ok'], 'deleted' => (bool) ($result['data']['deleted'] ?? $result['ok']), 'error' => $result['error']]);
    }
}

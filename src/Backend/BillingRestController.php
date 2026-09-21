<?php

namespace LingoWP\Backend;

use WP_REST_Request;
use WP_REST_Response;

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
        register_rest_route(self::NS, '/billing/plans', ['methods' => 'GET', 'callback' => [$this, 'plans'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/billing/packs', ['methods' => 'GET', 'callback' => [$this, 'packs'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/billing/addons', ['methods' => 'GET', 'callback' => [$this, 'addons'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(
            self::NS,
            '/billing/addons/coming-soon/(?P<slug>[^/]+)/notify',
            ['methods' => 'POST', 'callback' => [$this, 'notifyComingSoon'], 'permission_callback' => [$this, 'canManage']]
        );
        register_rest_route(self::NS, '/billing/entitlements', ['methods' => 'GET', 'callback' => [$this, 'entitlements'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/billing/transactions', ['methods' => 'GET', 'callback' => [$this, 'transactions'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/billing/usage', ['methods' => 'GET', 'callback' => [$this, 'usage'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/billing/subscription', ['methods' => 'POST', 'callback' => [$this, 'subscribe'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/billing/subscription/plan', ['methods' => 'POST', 'callback' => [$this, 'changePlan'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/billing/packs/order', ['methods' => 'POST', 'callback' => [$this, 'orderPack'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(
            self::NS,
            '/billing/packs/order/(?P<order_id>[^/]+)/capture',
            ['methods' => 'POST', 'callback' => [$this, 'captureOrder'], 'permission_callback' => [$this, 'canManage']]
        );
        register_rest_route(self::NS, '/billing/owner', ['methods' => 'GET', 'callback' => [$this, 'ownerStatus'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/billing/owner', ['methods' => 'POST', 'callback' => [$this, 'assignOwner'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/billing/websites', ['methods' => 'GET', 'callback' => [$this, 'listWebsites'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/billing/websites', ['methods' => 'POST', 'callback' => [$this, 'addWebsite'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(
            self::NS,
            '/billing/websites/(?P<uuid>[^/]+)',
            ['methods' => 'DELETE', 'callback' => [$this, 'deleteWebsite'], 'permission_callback' => [$this, 'canManage']]
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

    public function ownerStatus(): WP_REST_Response
    {
        return rest_ensure_response([
            'ok'          => true,
            'owner_email' => $this->connection->ownerEmail(),
            'verified'    => $this->connection->isOwnerEmailVerified(),
        ]);
    }

    public function assignOwner(WP_REST_Request $request): WP_REST_Response
    {
        $email = sanitize_email((string) $request->get_param('email'));
        if ($email === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_PAYLOAD'], 200);
        }

        $result = $this->backendClient->assignOwner($email);

        return rest_ensure_response(['ok' => $result['ok'], 'error' => $result['error']]);
    }

    public function listWebsites(): WP_REST_Response
    {
        $result = $this->backendClient->listWebsites();

        return rest_ensure_response([
            'ok'       => $result['ok'],
            'websites' => $result['data']['websites'] ?? [],
            'error'    => $result['error'],
        ]);
    }

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

    public function deleteWebsite(WP_REST_Request $request): WP_REST_Response
    {
        $uuid = sanitize_text_field((string) $request->get_param('uuid'));

        $result = $this->backendClient->deleteWebsite($uuid);

        return rest_ensure_response(['ok' => $result['ok'], 'deleted' => (bool) ($result['data']['deleted'] ?? $result['ok']), 'error' => $result['error']]);
    }
}

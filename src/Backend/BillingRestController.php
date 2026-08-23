<?php

namespace TransLangs\Backend;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Billing REST endpoints (namespace translangs/v1).
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
    private const NS = 'translangs/v1';

    public function __construct(
        private BackendClient $backendClient,
        private BillingEntitlementsService $entitlements,
        private ConnectionRepository $connection
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/billing/plans', ['methods' => 'GET', 'callback' => [$this, 'plans']] + $guard);
        register_rest_route(self::NS, '/billing/packs', ['methods' => 'GET', 'callback' => [$this, 'packs']] + $guard);
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

    public function entitlements(WP_REST_Request $request): WP_REST_Response
    {
        $fresh = (bool) $request->get_param('fresh');
        $data  = $this->entitlements->data($fresh);

        return rest_ensure_response(['ok' => $data !== null, 'entitlements' => $data, 'error' => $data !== null ? '' : 'ERR_BACKEND']);
    }

    public function transactions(): WP_REST_Response
    {
        $result = $this->backendClient->getTransactions();

        return rest_ensure_response(['ok' => $result['ok'], 'transactions' => $result['data']['transactions'] ?? [], 'error' => $result['error']]);
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
}

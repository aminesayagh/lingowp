<?php

namespace TransLangs\Backend;

/**
 * Thin wrapper over wp_remote_request for the Translangs backend (/api/v1).
 *
 * Every call returns the same array shape so callers never juggle WP_Error:
 *   ['ok' => bool, 'status' => int, 'data' => mixed, 'error' => string]
 *
 * 'error' is a stable ERR_* code (empty on success), never a human sentence.
 * All user-facing copy is resolved in the React SPA by mapping the ERR_* code to
 * localized text — PHP stays language-agnostic (see the error-code convention in
 * arch-onboarding-auth-lifecycle.md). The raw license key and Authorization
 * header are never logged.
 */
final class BackendClient
{
    private const TIMEOUT = 15;

    // /translations runs real model inference (batches of up to 25 items),
    // which routinely exceeds a normal API timeout — a cold model in
    // particular can take well over TIMEOUT before the first token, even
    // though the backend keeps working and eventually answers. PHP's own
    // max_execution_time (300s, docker/php/custom.ini) leaves plenty of room,
    // but this site is fronted by a Cloudflare Tunnel, whose edge enforces a
    // fixed ~100s origin response timeout (its own "524" limit, not something
    // cloudflared's local config can raise) — staying under that matters more
    // than PHP's own ceiling: past it, Cloudflare tears the connection down
    // before we ever get the chance to time out on our own terms.
    private const TRANSLATE_TIMEOUT = 120;

    public function __construct(
        private BackendEndpointResolver $resolver,
        private ConnectionRepository $connection
    ) {}

    /** GET /health — reachability check, no auth. */
    public function getHealth(): array
    {
        return $this->request('GET', '/health');
    }

    /**
     * POST /license/issue — first connect; returns the raw license key once.
     * Self-serve (no auth header) — defended backend-side by IP rate limiting
     * and domain validation instead.
     */
    public function issueLicense(array $payload): array
    {
        return $this->request('POST', '/license/issue', $payload);
    }

    /**
     * POST /license/recover — no auth; always returns {sent: true} regardless
     * of whether the domain matched anything. If it did, the backend rotates
     * the license server-side and emails the new raw key to the site's
     * stored admin_email — safe to call speculatively, no rate limit/cooldown.
     */
    public function recover(array $payload): array
    {
        return $this->request('POST', '/license/recover', $payload);
    }

    /**
     * POST /translations — batch translate (bearer + domain). Payload:
     *   { source_lang, target_langs:[], texts:[""] }
     * Success data: { translations:[{text,target_lang,translation}], failed:[{text,target_lang,reason}] }.
     */
    public function translate(array $payload): array
    {
        return $this->request('POST', '/translations', $payload, $this->authHeaders(), self::TRANSLATE_TIMEOUT);
    }

    /** GET /usage — AI word quota snapshot (bearer + domain); also doubles as the connection health probe. */
    public function usage(): array
    {
        return $this->request('GET', '/usage', null, $this->authHeaders());
    }

    /** GET /provider-keys — masked list of stored BYOK keys (bearer + domain). */
    public function getProviderKeys(): array
    {
        return $this->request('GET', '/provider-keys', null, $this->authHeaders());
    }

    /**
     * POST /provider-keys — validates the key against the provider, then stores it
     * encrypted. Payload: { provider, api_key }. Writes nothing on validation failure.
     */
    public function saveProviderKey(array $payload): array
    {
        return $this->request('POST', '/provider-keys', $payload, $this->authHeaders());
    }

    /** DELETE /provider-keys/{provider} — hard delete. */
    public function deleteProviderKey(string $provider): array
    {
        return $this->request('DELETE', '/provider-keys/' . rawurlencode($provider), null, $this->authHeaders());
    }

    /**
     * POST /insights/notify — Insights preview screen's "Notify me at launch"
     * button. Payload: { domain, email, plugin_version }. No auth: an
     * unlicensed/trial site can ask to be notified too.
     */
    public function notifyInsightsLaunch(array $payload): array
    {
        return $this->request('POST', '/insights/notify', $payload);
    }

    /** GET /billing/plans — public, no auth. Subscription plan catalog. */
    public function getPlans(): array
    {
        return $this->request('GET', '/billing/plans');
    }

    /** GET /billing/packs — public, no auth. One-off word-pack catalog. */
    public function getPacks(): array
    {
        return $this->request('GET', '/billing/packs');
    }

    /** GET /me/entitlements — bearer + domain. Canonical read for all billing UI state. */
    public function getEntitlements(): array
    {
        return $this->request('GET', '/me/entitlements', null, $this->authHeaders());
    }

    /**
     * POST /billing/subscription — start a new subscription (bearer + domain).
     * Payload: { plan_key }. Success data: { approve_url } — caller must
     * full-page-redirect the browser there, nothing is confirmed yet.
     */
    public function subscribe(string $planKey): array
    {
        return $this->request('POST', '/billing/subscription', ['plan_key' => $planKey], $this->authHeaders());
    }

    /**
     * POST /billing/subscription/plan — change plan on an active subscription
     * (bearer + domain). Payload: { plan_key }. Writes nothing locally; the new
     * allowance only applies from the next successful payment. approve_url can
     * be null (PayPal skips re-consent for some revisions) — the change is
     * still confirmed-but-deferred in that case, there is just nothing to
     * redirect to.
     */
    public function changePlan(string $planKey): array
    {
        return $this->request('POST', '/billing/subscription/plan', ['plan_key' => $planKey], $this->authHeaders());
    }

    /**
     * POST /billing/packs/order — buy a one-off word pack (bearer + domain).
     * Payload: { pack_id }. Requires an active subscription. Success data:
     * { order_id, approve_url } — redirect the browser, nothing is charged yet.
     */
    public function orderPack(string $packId): array
    {
        return $this->request('POST', '/billing/packs/order', ['pack_id' => $packId], $this->authHeaders());
    }

    /**
     * POST /billing/packs/order/{order_id}/capture — confirm a pack order after
     * the PayPal approval redirect returns (bearer + domain). Success data:
     * { status } — NOT proof of credit; crediting happens async via webhook, so
     * the caller must keep polling entitlements afterward.
     */
    public function capturePackOrder(string $orderId): array
    {
        return $this->request('POST', '/billing/packs/order/' . rawurlencode($orderId) . '/capture', null, $this->authHeaders());
    }

    /** GET /billing/subscription/transactions — receipts/billing history (bearer + domain), read-only. */
    public function getTransactions(): array
    {
        return $this->request('GET', '/billing/subscription/transactions', null, $this->authHeaders());
    }

    /**
     * GET /billing/usage — current-period usage (bearer + domain), read-only.
     * Success data: { words_translated, daily:[{date, words}] }. words_translated
     * is total words charged (allowance + purchased combined) — the backend has
     * no translated/reused split. daily omits zero-usage days entirely.
     */
    public function getUsageStats(): array
    {
        return $this->request('GET', '/billing/usage', null, $this->authHeaders());
    }

    private function request(string $method, string $path, ?array $body = null, array $headers = [], ?int $timeout = null): array
    {
        $base = $this->resolver->baseUrl();
        if ($base === '') {
            return $this->error('ERR_ORIGIN_NOT_CONFIGURED');
        }

        $args = [
            'method'  => $method,
            'timeout' => $timeout ?? self::TIMEOUT,
            'headers' => array_merge(['Accept' => 'application/json'], $headers),
        ];

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = wp_json_encode($body);
        }

        $response = wp_remote_request($base . $path, $args);

        // Do not surface WP_Error internals; a network failure is always ERR_UNREACHABLE.
        if (is_wp_error($response)) {
            return $this->error('ERR_UNREACHABLE');
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data   = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => ''];
        }

        return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => $this->errorForStatus($status, $data)];
    }

    /**
     * Most errors are a stable status code; a few (409/429/400/404 from the
     * /license/* routes) also carry a stable snake_case `detail` slug that
     * means something different per slug — read it where it matters. The 401
     * case is deliberately NOT slug-matched: the backend still returns a
     * human sentence there, not a slug, so any 401 is treated as "credential
     * invalid" regardless of its detail text.
     */
    private function errorForStatus(int $status, mixed $data): string
    {
        $detail = is_array($data) ? (string) ($data['detail'] ?? '') : '';

        return match (true) {
            $status === 409 && $detail === 'domain_mismatch'         => 'ERR_DOMAIN_MISMATCH',
            $status === 409 && $detail === 'domain_already_licensed' => 'ERR_DOMAIN_ALREADY_LICENSED',
            $status === 429 && $detail === 'rebind_cooldown_active'  => 'ERR_REBIND_COOLDOWN',
            $status === 429                                          => 'ERR_RATE_LIMITED',
            $status === 400 && $detail === 'domain_not_allowed'      => 'ERR_DOMAIN_NOT_ALLOWED',
            $status === 400 && $detail === 'domain_unreachable'      => 'ERR_DOMAIN_UNREACHABLE',
            $status === 400 && $detail === 'invalid_key'             => 'ERR_INVALID_API_KEY',     // /provider-keys: provider rejected the key
            $status === 502 && $detail === 'provider_unreachable'    => 'ERR_PROVIDER_UNREACHABLE', // /provider-keys: couldn't reach provider to validate
            $status === 404 && $detail === 'site_not_found'          => 'ERR_SITE_NOT_FOUND',
            $status === 400 && $detail === 'unknown_plan'            => 'ERR_UNKNOWN_PLAN',        // /billing/subscription(/plan)
            $status === 400 && $detail === 'unknown_pack'            => 'ERR_UNKNOWN_PACK',        // /billing/packs/order
            $status === 409 && $detail === 'already_subscribed'     => 'ERR_ALREADY_SUBSCRIBED',
            $status === 409 && $detail === 'no_active_subscription' => 'ERR_NO_ACTIVE_SUBSCRIPTION',
            $status === 409 && $detail === 'already_on_plan'        => 'ERR_ALREADY_ON_PLAN',
            $status === 409 && $detail === 'active_subscription_required' => 'ERR_SUBSCRIPTION_REQUIRED',
            $status === 403 && $detail === 'order_not_owned'         => 'ERR_ORDER_NOT_OWNED',      // /billing/packs/order/{id}/capture
            $status === 400                                          => 'ERR_INVALID_REQUEST', // shape/limit violation (e.g. >100 items, >25/group)
            $status === 401 || $status === 403                       => 'ERR_INVALID_TOKEN',    // 403 = revoked token; 401 detail is a sentence, never slug-matched
            $status === 404                                          => 'ERR_NOT_FOUND',
            $status === 422                                          => 'ERR_INVALID_PAYLOAD',
            default                                                   => 'ERR_BACKEND', // covers 503/500 — no retry here, see AiTranslationRunner
        };
    }

    private function error(string $code): array
    {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $code];
    }

    /** Every protected route needs both — the license key and the domain it's licensed for. */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->connection->token(),
            'X-Site-Domain' => $this->connection->domain(),
        ];
    }
}

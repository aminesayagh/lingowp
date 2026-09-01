<?php

namespace LingoWP\Backend;

/**
 * Thin wrapper over wp_remote_request for the LingoWP backend (/api/v1).
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
final class BackendClient implements TranslationBackendPort
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

    private BackendEndpointResolver $resolver;
    private ConnectionRepository $connection;

    public function __construct(
        BackendEndpointResolver $resolver,
        ConnectionRepository $connection
    ) {
        $this->resolver   = $resolver;
        $this->connection = $connection;
    }

    /** GET /health — reachability check, no auth. */
    public function getHealth(): array
    {
        return $this->request('GET', '/health');
    }

    /**
     * POST /license/connect — bootstrap (no auth). A `license_key` in the
     * response is always the primary success signal — a fresh licence is
     * issued synchronously in every case except one: `{sent: true}` with no
     * key means admin_email matched a verified owner of an existing licence
     * (attach-website), and a confirm email went to that owner instead.
     * `recovery_available` can additionally be true alongside a fresh
     * `license_key` — a reclaim email went to the verified owner of a
     * matching older licence, but this install already works either way.
     */
    public function connect(array $payload): array
    {
        return $this->request('POST', '/license/connect', $payload);
    }

    /**
     * POST /license/confirm — no auth; the single callback every emailed
     * token lands on. Payload: { token }. Dispatches server-side on what
     * kind of action the token was for; success data carries whatever the
     * caller needs to apply: { license_key, site_id } for attach-website,
     * { site_id } alone (no key) for a licence reclaim, or { owner_email }
     * for assign-owner. `400 invalid_or_expired_token` if unknown/used/
     * expired; `410 reclaim_target_gone` if a reclaim's target disappeared
     * before it was confirmed.
     */
    public function confirmToken(string $token): array
    {
        return $this->request('POST', '/license/confirm', ['token' => $token]);
    }

    /**
     * POST /license/rotate-key — bearer only, no body. Mints a fresh key for
     * the same site/licence/subscription/word-balance/owner_email; the old
     * key stops working immediately. Success data: { license_key }. 402/410
     * mean the caller must connect() instead — never call this against a key
     * already in that state.
     */
    public function rotateKey(): array
    {
        return $this->request('POST', '/license/rotate-key', null, $this->authHeaders());
    }

    /**
     * POST /license/site/change — bearer only. Applies immediately, response
     * is always { sent: false }, never a 409.
     */
    public function updateDomain(string $domain): array
    {
        return $this->request('POST', '/license/site/change', ['field' => 'domain', 'value' => $domain], $this->authHeaders());
    }

    /** POST /license/site/change — bearer only. Applies immediately, no confirmation. */
    public function updateAdminEmail(string $email): array
    {
        return $this->request('POST', '/license/site/change', ['field' => 'admin_email', 'value' => $email], $this->authHeaders());
    }

    /**
     * POST /owner/assign-owner — bearer + domain. Payload: { new_owner_email }.
     * Emails a confirm token to the proposed address; the callback is the
     * same generic confirmToken() every other flow uses, this time returning
     * { owner_email } instead of a license credential. This call's own
     * success data is empty — nothing is set until the token is confirmed.
     */
    public function assignOwner(string $newOwnerEmail): array
    {
        return $this->request('POST', '/owner/assign-owner', ['new_owner_email' => $newOwnerEmail], $this->authHeaders());
    }

    /**
     * GET /owner/websites — bearer + domain. Every non-revoked site under the
     * license, oldest first. Success data: { websites: [{site_id, domain,
     * admin_email, default_language}] }. A still-pending, unconfirmed add
     * doesn't appear here — this is a confirmed-sites view only.
     */
    public function listWebsites(): array
    {
        return $this->request('GET', '/owner/websites', null, $this->authHeaders());
    }

    /**
     * POST /owner/websites — bearer + domain (the *existing* site the owner
     * is adding a new one from). Payload: { domain, admin_email }. Requires a
     * verified owner_email and room under sites_limit (403 otherwise). Always
     * returns { sent: true } — the new site doesn't exist until its own
     * confirm link (landing on the *new* domain) is clicked.
     */
    public function addWebsite(string $domain, string $adminEmail): array
    {
        return $this->request('POST', '/owner/websites', ['domain' => $domain, 'admin_email' => $adminEmail], $this->authHeaders());
    }

    /**
     * DELETE /owner/websites/{uuid} — bearer + domain (any site under the
     * license, not necessarily the one being deleted). Immediate synchronous
     * hard delete, no confirmation email. Success data: { deleted: true }.
     */
    public function deleteWebsite(string $uuid): array
    {
        return $this->request('DELETE', '/owner/websites/' . rawurlencode($uuid), null, $this->authHeaders());
    }

    /**
     * POST /translations — batch translate (bearer + domain). Payload:
     *   { source_lang, target_langs:[], languages:{ "<lang>": { provider, writing_style? } }, texts:[""] }
     * `languages` carries per-target AI config: provider ('platform' for the
     * subscription model, or 'openai'/'anthropic' for a stored key) and the
     * language's saved writing style (omitted when blank).
     * Success data: { translations:[{text,target_lang,translation}], failed:[{text,target_lang,reason}] }.
     */
    public function translate(array $payload): array
    {
        return $this->request('POST', '/translations', $payload, $this->authHeaders(), self::TRANSLATE_TIMEOUT);
    }

    public function submitTasks(array $payload): array
    {
        return $this->request('POST', '/translations/tasks', $payload, $this->authHeaders());
    }

    public function pullResults(int $cursor = 0): array
    {
        return $this->request('GET', '/translations/results?' . http_build_query(['cursor' => $cursor]), null, $this->authHeaders());
    }

    public function ackResults(array $items): array
    {
        return $this->request('POST', '/translations/results/ack', ['items' => $items], $this->authHeaders());
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

    /** POST /support/suggestions — authenticated product suggestion. */
    public function submitSupportSuggestion(string $message): array
    {
        return $this->request('POST', '/support/suggestions', ['message' => $message], $this->authHeaders());
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

    /**
     * GET /billing/addons?lang= — bearer + domain. Paid content-integration
     * add-on catalog (title/description/advantages localized to $lang, English
     * fallback per field) plus a `coming_soon` array of informational-only
     * entries with no price/version/owned fields.
     */
    public function getAddons(string $lang): array
    {
        return $this->request('GET', '/billing/addons?' . http_build_query(['lang' => $lang]), null, $this->authHeaders());
    }

    /**
     * GET /billing/addons/{id}/download — bearer + domain. The add-on plugin
     * zip; success `data` is the raw zip bytes, NOT JSON — every other status
     * (402 addon_not_owned, 403 addon_revoked, 404 unknown_addon/artifact_missing)
     * returns the usual JSON error body, decoded as normal.
     */
    public function downloadAddonZip(string $addonId): array
    {
        return $this->request('GET', '/billing/addons/' . rawurlencode($addonId) . '/download', null, $this->authHeaders(), null, false);
    }

    /**
     * POST /billing/addons/order — buy a paid add-on (bearer + domain).
     * Payload: { addon_id }. Success data: { order_id, approve_url } —
     * redirect the browser, nothing is charged yet. 400 unknown_addon/free_addon,
     * 409 addon_already_owned.
     */
    public function orderAddon(string $addonId): array
    {
        return $this->request('POST', '/billing/addons/order', ['addon_id' => $addonId], $this->authHeaders());
    }

    /**
     * POST /billing/addons/orders/{order_id}/capture — confirm an add-on order
     * after the PayPal approval redirect returns (bearer + domain). Success
     * data: { status } — NOT proof of ownership; the verified webhook grants
     * it, so the caller must keep polling GET /billing/addons afterward.
     */
    public function captureAddonOrder(string $orderId): array
    {
        return $this->request('POST', '/billing/addons/orders/' . rawurlencode($orderId) . '/capture', null, $this->authHeaders());
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

    /** GET /billing/transactions — unified subscription and word-pack history. */
    public function getTransactions(int $page = 1, int $pageSize = 20): array
    {
        $query = http_build_query(['page' => $page, 'page_size' => $pageSize]);

        return $this->request('GET', '/billing/transactions?' . $query, null, $this->authHeaders());
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

    /**
     * $decodeJson only governs the success path — a non-2xx body is always
     * JSON per the backend's own error contract, decoded either way so
     * errorForStatus() can read `detail`. Only downloadAddonZip() passes
     * false, for the one route whose success body is raw zip bytes.
     */
    private function request(string $method, string $path, ?array $body = null, array $headers = [], ?int $timeout = null, bool $decodeJson = true): array
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

        $status  = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);

        if ($status >= 200 && $status < 300) {
            $data = $decodeJson ? json_decode($rawBody, true) : $rawBody;

            return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => ''];
        }

        $errorData = json_decode($rawBody, true);

        return ['ok' => false, 'status' => $status, 'data' => $errorData, 'error' => $this->errorForStatus($status, $errorData)];
    }

    /**
     * Most errors are a stable status code; a few (410/429/400/404 from the
     * /license/* routes) also carry a stable snake_case `detail` slug that
     * means something different per slug — read it where it matters. The 401
     * case is deliberately NOT slug-matched: the backend still returns a
     * human sentence there, not a slug, so any 401 is treated as "credential
     * invalid" regardless of its detail text.
     */
    private function errorForStatus(int $status, $data): string
    {
        $detail = is_array($data) ? (string) ($data['detail'] ?? '') : '';

        switch (true) {
            case $status === 429 && $detail === 'rebind_cooldown_active':
                return 'ERR_REBIND_COOLDOWN';
            case $status === 429:
                return 'ERR_RATE_LIMITED';
            case $status === 400 && $detail === 'domain_not_allowed':
                return 'ERR_DOMAIN_NOT_ALLOWED';
            case $status === 400 && $detail === 'domain_unreachable':
                return 'ERR_DOMAIN_UNREACHABLE';
            case $status === 400 && $detail === 'invalid_or_expired_token': // /license/confirm
                return 'ERR_INVALID_CONFIRM_TOKEN';
            case $status === 410 && $detail === 'reclaim_target_gone': // /license/confirm
                return 'ERR_RECLAIM_TARGET_GONE';
            case $status === 400 && $detail === 'invalid_key': // /provider-keys: provider rejected the key
                return 'ERR_INVALID_API_KEY';
            case $status === 502 && $detail === 'provider_unreachable': // /provider-keys: couldn't reach provider to validate
                return 'ERR_PROVIDER_UNREACHABLE';
            case $status === 404 && $detail === 'site_not_found':
                return 'ERR_SITE_NOT_FOUND';
            case $status === 400 && $detail === 'unknown_plan': // /billing/subscription(/plan)
                return 'ERR_UNKNOWN_PLAN';
            case $status === 400 && $detail === 'unknown_pack': // /billing/packs/order
                return 'ERR_UNKNOWN_PACK';
            case $status === 409 && $detail === 'already_subscribed':
                return 'ERR_ALREADY_SUBSCRIBED';
            case $status === 409 && $detail === 'no_active_subscription':
                return 'ERR_NO_ACTIVE_SUBSCRIPTION';
            case $status === 409 && $detail === 'already_on_plan':
                return 'ERR_ALREADY_ON_PLAN';
            case $status === 409 && $detail === 'billing_cycle_change_not_supported': // /billing/subscription/plan: crossing cadence isn't a supported change-plan action
                return 'ERR_BILLING_CYCLE_CHANGE_NOT_SUPPORTED';
            case $status === 409 && $detail === 'plan_change_already_pending': // /billing/subscription/plan: a revision is already approved and scheduled
                return 'ERR_PLAN_CHANGE_PENDING';
            case $status === 409 && $detail === 'active_subscription_required':
                return 'ERR_SUBSCRIPTION_REQUIRED';
            case $status === 409 && $detail === 'owner_email_already_taken': // /owner/assign-owner
                return 'ERR_OWNER_EMAIL_TAKEN';
            case $status === 403 && $detail === 'owner_email_required': // POST /owner/websites
                return 'ERR_OWNER_EMAIL_REQUIRED';
            case $status === 403 && $detail === 'sites_limit_reached': // POST /owner/websites
                return 'ERR_SITES_LIMIT_REACHED';
            case $status === 409 && $detail === 'domain_claimed': // POST /owner/websites
                return 'ERR_DOMAIN_CLAIMED';
            case $status === 403 && $detail === 'order_not_owned': // /billing/packs/order/{id}/capture
                return 'ERR_ORDER_NOT_OWNED';
            case $status === 400 && $detail === 'unknown_addon': // /billing/addons/order, /billing/addons/{id}/download
                return 'ERR_UNKNOWN_ADDON';
            case $status === 400 && $detail === 'free_addon': // /billing/addons/order — free SKUs have no checkout, install directly instead
                return 'ERR_FREE_ADDON';
            case $status === 409 && $detail === 'addon_already_owned': // /billing/addons/order
                return 'ERR_ADDON_ALREADY_OWNED';
            case $status === 402 && $detail === 'addon_not_owned': // /billing/addons/{id}/download
                return 'ERR_ADDON_NOT_OWNED';
            case $status === 403 && $detail === 'addon_revoked': // /billing/addons/{id}/download
                return 'ERR_ADDON_REVOKED';
            case $status === 404 && $detail === 'artifact_missing': // /billing/addons/{id}/download
                return 'ERR_ADDON_ARTIFACT_MISSING';
            case $status === 402 && $detail === 'site_over_plan_limit': // /translations: over_slot asked for platform AI anyway
                return 'ERR_SITE_OVER_PLAN_LIMIT';
            case $status === 402 && $detail === 'hosted_ai_unavailable': // /translations: no active subscription
                return 'ERR_HOSTED_AI_UNAVAILABLE';
            case $status === 402 && $detail === 'insufficient_ai_words': // /translations: allowance + word packs exhausted
                return 'ERR_INSUFFICIENT_AI_WORDS';
            case $status === 402: // key valid, license_id IS NULL — reconnect to reattach
                return 'ERR_LICENSE_DETACHED';
            case $status === 410: // key valid but revoked — delete key, reconnect
                return 'ERR_LICENSE_REVOKED';
            case $status === 400: // shape/limit violation (e.g. >100 items, >25/group)
                return 'ERR_INVALID_REQUEST';
            case $status === 401 || $status === 403: // key unrecognised — delete key, reconnect; 401 detail is a sentence, never slug-matched
                return 'ERR_INVALID_TOKEN';
            case $status === 404:
                return 'ERR_NOT_FOUND';
            case $status === 422:
                return 'ERR_INVALID_PAYLOAD';
            default: // covers 503/500 — no retry anywhere in the AI translate path
                return 'ERR_BACKEND';
        }
    }

    private function error(string $code): array
    {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $code];
    }

    /** Every protected route needs only the license key — it is the whole identity, valid from any domain. */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->connection->token(),
        ];
    }
}

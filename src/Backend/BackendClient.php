<?php

namespace LingoWP\Backend;

final class BackendClient implements TranslationBackendPort
{
    private const TIMEOUT = 15;

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

    public function getHealth(?int $timeout = null): array
    {
        return $this->request('GET', '/health', null, [], $timeout);
    }

    public function connect(array $payload): array
    {
        return $this->request('POST', '/license/connect', $payload);
    }

    public function confirmToken(string $token): array
    {
        return $this->request('POST', '/license/confirm', ['token' => $token]);
    }

    public function rotateKey(): array
    {
        return $this->request('POST', '/license/rotate-key', null, $this->authHeaders());
    }

    public function updateDomain(string $domain): array
    {
        return $this->request('POST', '/license/site/change', ['field' => 'domain', 'value' => $domain], $this->authHeaders());
    }

    public function updateAdminEmail(string $email): array
    {
        return $this->request('POST', '/license/site/change', ['field' => 'admin_email', 'value' => $email], $this->authHeaders());
    }

    public function assignOwner(string $newOwnerEmail): array
    {
        return $this->request('POST', '/owner/assign-owner', ['new_owner_email' => $newOwnerEmail], $this->authHeaders());
    }

    public function listWebsites(): array
    {
        return $this->request('GET', '/owner/websites', null, $this->authHeaders());
    }

    public function addWebsite(string $domain, string $adminEmail): array
    {
        return $this->request('POST', '/owner/websites', ['domain' => $domain, 'admin_email' => $adminEmail], $this->authHeaders());
    }

    public function deleteWebsite(string $uuid): array
    {
        return $this->request('DELETE', '/owner/websites/' . rawurlencode($uuid), null, $this->authHeaders());
    }

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

    public function getProviderKeys(): array
    {
        return $this->request('GET', '/provider-keys', null, $this->authHeaders());
    }

    public function saveProviderKey(array $payload): array
    {
        return $this->request('POST', '/provider-keys', $payload, $this->authHeaders());
    }

    public function deleteProviderKey(string $provider): array
    {
        return $this->request('DELETE', '/provider-keys/' . rawurlencode($provider), null, $this->authHeaders());
    }

    public function submitSupportSuggestion(string $message): array
    {
        return $this->request('POST', '/support/suggestions', ['message' => $message], $this->authHeaders());
    }

    public function getPlans(): array
    {
        return $this->request('GET', '/billing/plans');
    }

    public function getPacks(): array
    {
        return $this->request('GET', '/billing/packs');
    }

    public function getAddons(string $lang): array
    {
        return $this->request('GET', '/billing/addons?' . http_build_query(['lang' => $lang]), null, $this->authHeaders());
    }

    public function getEntitlements(): array
    {
        return $this->request('GET', '/me/entitlements', null, $this->authHeaders());
    }

    public function subscribe(string $planKey): array
    {
        return $this->request('POST', '/billing/subscription', ['plan_key' => $planKey], $this->authHeaders());
    }

    public function changePlan(string $planKey): array
    {
        return $this->request('POST', '/billing/subscription/plan', ['plan_key' => $planKey], $this->authHeaders());
    }

    public function orderPack(string $packId): array
    {
        return $this->request('POST', '/billing/packs/order', ['pack_id' => $packId], $this->authHeaders());
    }

    public function capturePackOrder(string $orderId): array
    {
        return $this->request('POST', '/billing/packs/order/' . rawurlencode($orderId) . '/capture', null, $this->authHeaders());
    }

    public function getTransactions(int $page = 1, int $pageSize = 20): array
    {
        $query = http_build_query(['page' => $page, 'page_size' => $pageSize]);

        return $this->request('GET', '/billing/transactions?' . $query, null, $this->authHeaders());
    }

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

        if (is_wp_error($response)) {
            return $this->error('ERR_UNREACHABLE');
        }

        $status  = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);

        if ($status >= 200 && $status < 300) {
            $data = json_decode($rawBody, true);

            return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => ''];
        }

        $errorData = json_decode($rawBody, true);

        return ['ok' => false, 'status' => $status, 'data' => $errorData, 'error' => $this->errorForStatus($status, $errorData)];
    }

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
            case $status === 400 && $detail === 'invalid_or_expired_token':
                return 'ERR_INVALID_CONFIRM_TOKEN';
            case $status === 410 && $detail === 'reclaim_target_gone':
                return 'ERR_RECLAIM_TARGET_GONE';
            case $status === 400 && $detail === 'invalid_key':
                return 'ERR_INVALID_API_KEY';
            case $status === 502 && $detail === 'provider_unreachable':
                return 'ERR_PROVIDER_UNREACHABLE';
            case $status === 404 && $detail === 'site_not_found':
                return 'ERR_SITE_NOT_FOUND';
            case $status === 400 && $detail === 'unknown_plan':
                return 'ERR_UNKNOWN_PLAN';
            case $status === 400 && $detail === 'unknown_pack':
                return 'ERR_UNKNOWN_PACK';
            case $status === 409 && $detail === 'already_subscribed':
                return 'ERR_ALREADY_SUBSCRIBED';
            case $status === 409 && $detail === 'no_active_subscription':
                return 'ERR_NO_ACTIVE_SUBSCRIPTION';
            case $status === 409 && $detail === 'already_on_plan':
                return 'ERR_ALREADY_ON_PLAN';
            case $status === 409 && $detail === 'billing_cycle_change_not_supported':
                return 'ERR_BILLING_CYCLE_CHANGE_NOT_SUPPORTED';
            case $status === 409 && $detail === 'plan_change_already_pending':
                return 'ERR_PLAN_CHANGE_PENDING';
            case $status === 409 && $detail === 'active_subscription_required':
                return 'ERR_SUBSCRIPTION_REQUIRED';
            case $status === 409 && $detail === 'owner_email_already_taken':
                return 'ERR_OWNER_EMAIL_TAKEN';
            case $status === 403 && $detail === 'owner_email_required':
                return 'ERR_OWNER_EMAIL_REQUIRED';
            case $status === 403 && $detail === 'sites_limit_reached':
                return 'ERR_SITES_LIMIT_REACHED';
            case $status === 409 && $detail === 'domain_claimed':
                return 'ERR_DOMAIN_CLAIMED';
            case $status === 403 && $detail === 'order_not_owned':
                return 'ERR_ORDER_NOT_OWNED';
            case $status === 402 && $detail === 'site_over_plan_limit':
                return 'ERR_SITE_OVER_PLAN_LIMIT';
            case $status === 402 && $detail === 'hosted_ai_unavailable':
                return 'ERR_HOSTED_AI_UNAVAILABLE';
            case $status === 402 && $detail === 'insufficient_ai_words':
                return 'ERR_INSUFFICIENT_AI_WORDS';
            case $status === 402:
                return 'ERR_LICENSE_DETACHED';
            case $status === 410:
                return 'ERR_LICENSE_REVOKED';
            case $status === 400:
                return 'ERR_INVALID_REQUEST';
            case $status === 401 || $status === 403:
                return 'ERR_INVALID_TOKEN';
            case $status === 404:
                return 'ERR_NOT_FOUND';
            case $status === 422:
                return 'ERR_INVALID_PAYLOAD';
            default:
                return 'ERR_BACKEND';
        }
    }

    private function error(string $code): array
    {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $code];
    }

    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->connection->token(),
        ];
    }
}

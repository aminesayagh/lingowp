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
 * arch-onboarding-auth-lifecycle.md). The raw token, Authorization, and
 * X-Provision-Key headers are never logged.
 */
final class BackendClient
{
    private const TIMEOUT = 15;

    public function __construct(
        private BackendEndpointResolver $resolver,
        private ConnectionRepository $connection
    ) {}

    /** GET /health — reachability check, no auth. */
    public function getHealth(): array
    {
        return $this->request('GET', '/health');
    }

    /** GET /auth/categories — provision-key auth. */
    public function getCategories(): array
    {
        return $this->request('GET', '/auth/categories', null, $this->provisionHeaders());
    }

    /** POST /auth/site — first connect; returns the raw token once. */
    public function createSite(array $payload): array
    {
        return $this->request('POST', '/auth/site', $payload, $this->provisionHeaders());
    }

    /** PUT /auth/site — sync site metadata (bearer). */
    public function syncSite(array $payload): array
    {
        return $this->request('PUT', '/auth/site', $payload, $this->bearerHeaders());
    }

    /** GET /auth/me — verify token/connection (bearer). */
    public function me(): array
    {
        return $this->request('GET', '/auth/me', null, $this->bearerHeaders());
    }

    /** POST /auth/rotate-token — replace token (bearer). */
    public function rotateToken(): array
    {
        return $this->request('POST', '/auth/rotate-token', null, $this->bearerHeaders());
    }

    /** POST /auth/revoke-token — disconnect (bearer). */
    public function revokeToken(): array
    {
        return $this->request('POST', '/auth/revoke-token', null, $this->bearerHeaders());
    }

    /**
     * POST /translate — batch translate (bearer). Payload:
     *   { source_language, target_language, ai_style, categories[], texts:[{id,text}] }
     * Success data: { translations:[{id,text}], words_used }.
     */
    public function translate(array $payload): array
    {
        return $this->request('POST', '/translate', $payload, $this->bearerHeaders());
    }

    /** GET /usage — AI word quota snapshot (bearer). */
    public function usage(): array
    {
        return $this->request('GET', '/usage', null, $this->bearerHeaders());
    }

    private function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $base = $this->resolver->baseUrl();
        if ($base === '') {
            return $this->error('ERR_ORIGIN_NOT_CONFIGURED');
        }

        $args = [
            'method'  => $method,
            'timeout' => self::TIMEOUT,
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

        return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => $this->errorForStatus($status)];
    }

    private function errorForStatus(int $status): string
    {
        return match ($status) {
            401     => 'ERR_INVALID_TOKEN',
            404     => 'ERR_NOT_FOUND',
            422     => 'ERR_INVALID_PAYLOAD',
            default => 'ERR_BACKEND',
        };
    }

    private function error(string $code): array
    {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $code];
    }

    private function provisionHeaders(): array
    {
        return ['X-Provision-Key' => $this->resolver->provisionKey()];
    }

    private function bearerHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->connection->token()];
    }
}

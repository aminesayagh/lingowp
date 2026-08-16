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
            $status === 404 && $detail === 'site_not_found'          => 'ERR_SITE_NOT_FOUND',
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

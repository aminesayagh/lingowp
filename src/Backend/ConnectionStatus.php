<?php

namespace TransLangs\Backend;

/**
 * Tracks whether AI-backed features are usable right now.
 *
 * The plugin works fully offline; only AI actions require the backend. This class
 * is the single source of truth for "AI available", cached in a transient so the
 * gate never blocks a page load. There is no background poller: freshness comes
 * from a live probe whenever it actually matters — on dashboard load (check(),
 * connect()) and the instant a translate call itself hits a failure
 * (markUnavailable()) — rather than a fixed interval ticking independent of
 * anyone looking. Good information at the moment it's needed, not perpetual sync.
 *
 * The probe is /me/entitlements (not /health): AI needs a *valid license for
 * this domain*, so the check must distinguish backend-down from an
 * invalid/mismatched/detached/revoked credential — /health only proves
 * reachability. There is no dedicated probe route; /me/entitlements is reused
 * because it already sits behind the same bearer + X-Site-Domain auth every
 * protected route requires — and reused via
 * BillingEntitlementsService::rawEntitlements() specifically, not a direct
 * BackendClient call, so a near-simultaneous entitlements read (Billing/quota
 * screens also ask for one) shares the same cached call instead of doubling
 * traffic. Status shape: ['available' => bool, 'error' => string] where error
 * is an ERR_* code ('' when available): ERR_NOT_CONNECTED | ERR_INVALID_TOKEN |
 * ERR_LICENSE_REVOKED | ERR_LICENSE_DETACHED | ERR_DOMAIN_MISMATCH | ERR_UNREACHABLE.
 */
final class ConnectionStatus
{
    private const TRANSIENT = 'translangs_backend_status';
    private const TTL       = 15 * MINUTE_IN_SECONDS;

    public function __construct(
        private BillingEntitlementsService $entitlements,
        private ConnectionRepository $connection
    ) {}

    /** @return array{available: bool, error: string} */
    public function current(): array
    {
        $cached = get_transient(self::TRANSIENT);

        // Only a cached *success* is trusted — that's what the TTL exists to
        // save (a page load skipping a live probe while things are healthy).
        // A cached failure is never reused: a failed probe costs the backend
        // nothing (no AI/usage spent), so a real outage is cheap to re-verify
        // on every page load instead of parroting a stale "unavailable" for
        // up to TTL after the backend has already recovered.
        if (is_array($cached) && $cached['available']) {
            return $cached;
        }

        return $this->refresh();
    }

    /** @return array{available: bool, error: string} */
    public function refresh(): array
    {
        if (!$this->connection->isConnected()) {
            return $this->store(false, 'ERR_NOT_CONNECTED');
        }

        // /me/entitlements proves reachability AND credential validity in one call.
        $result = $this->entitlements->rawEntitlements();

        return $result['ok']
            ? $this->store(true, '')
            : $this->store(false, $result['error']);
    }

    /**
     * Record a failure already observed by a caller (e.g. a translate request
     * that hit the backend and got a connection-level error) — no extra live
     * probe, just persist what's already known so the next status read is
     * accurate immediately instead of waiting for the next live check.
     */
    public function markUnavailable(string $error): void
    {
        $this->store(false, $error);
    }

    /** Dev-only reset tool: drop the cached status. */
    public function clearCache(): void
    {
        delete_transient(self::TRANSIENT);
    }

    /** @return array{available: bool, error: string} */
    private function store(bool $available, string $error): array
    {
        $status = ['available' => $available, 'error' => $error];
        set_transient(self::TRANSIENT, $status, self::TTL);

        return $status;
    }
}

<?php

namespace TransLangs\Api;

/**
 * Tracks whether AI-backed features are usable right now (v1.1 resilience).
 *
 * The plugin works fully offline; only AI actions require the backend. This class
 * is the single source of truth for "AI available", cached in a transient so the
 * gate never blocks a page load. It is kept fresh by a recurring Action Scheduler
 * health check (GET /auth/me) while connected, and refreshed on demand by the
 * connection check/connect endpoints.
 *
 * The probe is /auth/me (not /health): AI needs a *valid token*, so the check must
 * distinguish backend-down from a revoked/expired token — /health only proves
 * reachability. Status shape: ['available' => bool, 'error' => string] where error
 * is an ERR_* code ('' when available): ERR_NOT_CONNECTED | ERR_INVALID_TOKEN |
 * ERR_UNREACHABLE.
 */
final class ConnectionStatus
{
    public const HOOK      = 'translangs_backend_health_check';
    public const GROUP     = 'translangs';
    private const TRANSIENT = 'translangs_backend_status';
    private const TTL       = 15 * MINUTE_IN_SECONDS;
    private const INTERVAL  = 15 * MINUTE_IN_SECONDS;

    public function __construct(
        private BackendClient $backend,
        private ConnectionRepository $connection
    ) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'refresh']);
        // Defer scheduling to init: the Action Scheduler data store is not ready at
        // plugins_loaded (boot), so as_*_action() must not be called before then.
        add_action('init', [$this, 'syncSchedule']);
    }

    /** @return array{available: bool, error: string} */
    public function current(): array
    {
        $cached = get_transient(self::TRANSIENT);

        // Lazy refresh when the cache is cold (called only in admin/REST context).
        return is_array($cached) ? $cached : $this->refresh();
    }

    /** @return array{available: bool, error: string} */
    public function refresh(): array
    {
        if (!$this->connection->isConnected()) {
            return $this->store(false, 'ERR_NOT_CONNECTED');
        }

        // /auth/me proves reachability AND token validity in one call.
        $result = $this->backend->me();

        return $result['ok']
            ? $this->store(true, '')
            : $this->store(false, $result['error']);
    }

    /** Keep the recurring check scheduled only while connected. */
    public function syncSchedule(): void
    {
        if (!function_exists('as_schedule_recurring_action') || !function_exists('as_next_scheduled_action')) {
            return;
        }

        $scheduled = as_next_scheduled_action(self::HOOK, [], self::GROUP);

        if ($this->connection->isConnected()) {
            if (!$scheduled) {
                as_schedule_recurring_action(time() + self::INTERVAL, self::INTERVAL, self::HOOK, [], self::GROUP);
            }
        } elseif ($scheduled && function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, [], self::GROUP);
            delete_transient(self::TRANSIENT);
        }
    }

    /** @return array{available: bool, error: string} */
    private function store(bool $available, string $error): array
    {
        $status = ['available' => $available, 'error' => $error];
        set_transient(self::TRANSIENT, $status, self::TTL);

        return $status;
    }
}

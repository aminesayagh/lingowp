<?php

namespace TransLangs\Backend;

/**
 * Recurring Action Scheduler job: rotate the API token every 30 days.
 *
 * Key hygiene, not expiry-driven refresh — per the backend, tokens are opaque,
 * non-expiring API keys (no JWT/refresh-token model), so rotation just bounds
 * how long a leaked token stays useful.
 *
 * Failure policy:
 *   - network / origin unset -> retryable: throw so Action Scheduler retries.
 *     The old token is never touched before a new one is confirmed issued.
 *   - 401 invalid token      -> a dead token can't rotate itself (rotate-token
 *     requires a valid bearer) — re-provision via SiteConnector instead.
 *   - other (404/422/5xx)    -> non-retryable: record a breadcrumb, stop; the
 *     next scheduled run tries again in 30 days.
 */
final class TokenRotationJob
{
    public const HOOK  = 'translangs_rotate_token';
    public const GROUP = 'translangs';

    private const INTERVAL        = 30 * DAY_IN_SECONDS;
    private const ERROR_TRANSIENT = 'translangs_last_token_rotation_error';
    private const ERROR_TTL       = 31 * DAY_IN_SECONDS; // outlives one interval

    public function __construct(
        private BackendClient $backend,
        private ConnectionRepository $connection,
        private ConnectionStatus $status,
        private SiteConnector $siteConnector
    ) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        // Same reasoning as ConnectionStatus::register(): Action Scheduler's
        // data store isn't ready before init.
        add_action('init', [$this, 'syncSchedule']);
    }

    /** Keep the recurring rotation scheduled only while connected. */
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
        }
    }

    public function run(): void
    {
        if (!$this->connection->isConnected()) {
            return;
        }

        $result = $this->backend->rotateToken();

        if ($result['ok']) {
            $data  = is_array($result['data']) ? $result['data'] : [];
            $token = (string) ($data['api_token'] ?? $data['token'] ?? '');
            if ($token !== '') {
                // Swap only now that the new token is confirmed issued — the
                // old one is never overwritten before this point.
                $this->connection->store($this->connection->siteId(), $token);
                $this->status->refresh();
            }
            delete_transient(self::ERROR_TRANSIENT);
            return;
        }

        // Retryable transport failure: let Action Scheduler retry.
        if (in_array($result['error'], ['ERR_UNREACHABLE', 'ERR_ORIGIN_NOT_CONFIGURED'], true)) {
            throw new \RuntimeException('translangs token rotation unreachable');
        }

        // A dead/invalid token can't rotate itself — re-provision instead.
        if ($result['error'] === 'ERR_INVALID_TOKEN') {
            $reconnect = $this->siteConnector->connect();
            if ($reconnect['ok']) {
                delete_transient(self::ERROR_TRANSIENT);
            } else {
                set_transient(self::ERROR_TRANSIENT, $reconnect['error'], self::ERROR_TTL);
            }
            return;
        }

        // Other non-retryable errors (404/422/backend 5xx, etc.).
        set_transient(self::ERROR_TRANSIENT, $result['error'], self::ERROR_TTL);
    }

    /** Dev-only reset tool: stop the recurring rotation and drop the last error. */
    public function unschedule(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, [], self::GROUP);
        }
        delete_transient(self::ERROR_TRANSIENT);
    }
}

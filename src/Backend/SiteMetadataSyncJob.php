<?php

namespace TransLangs\Backend;

/**
 * Action Scheduler job that pushes current site metadata to the backend (P4).
 *
 * Runs off-request. Builds the full metadata snapshot at run time (so it always
 * reflects the latest state, not whatever was true when the job was queued) and
 * sends one consolidated PUT /auth/site. Categories are intentionally omitted —
 * the backend keeps its current selection unless the user explicitly changes it.
 *
 * Failure policy:
 *   - network / origin unset  -> retryable: throw so Action Scheduler retries.
 *   - 401 invalid token       -> safe: do NOT clear the token here; a check will surface it.
 *   - 404 not found           -> needs reconnect: record a short transient, no retry.
 *   - 422 invalid payload     -> safe log via transient, no retry.
 */
final class SiteMetadataSyncJob
{
    public const HOOK  = 'translangs_sync_site_metadata';
    public const GROUP = 'translangs';

    private const ERROR_TRANSIENT = 'translangs_last_site_sync_error';
    private const ERROR_TTL       = 20 * MINUTE_IN_SECONDS;

    public function __construct(
        private BackendClient $backend,
        private ConnectionRepository $connection,
        private SiteMetadataProvider $metadata
    ) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    public function run(): void
    {
        if (!$this->connection->isConnected()) {
            return;
        }

        $result = $this->backend->syncSite($this->metadata->build());

        if ($result['ok']) {
            delete_transient(self::ERROR_TRANSIENT);
            return;
        }

        // Retryable transport failures: let Action Scheduler retry by throwing.
        if (in_array($result['error'], ['ERR_UNREACHABLE', 'ERR_ORIGIN_NOT_CONFIGURED'], true)) {
            throw new \RuntimeException('translangs site sync unreachable');
        }

        // Non-retryable (401/404/422): record a short, safe breadcrumb and stop.
        set_transient(self::ERROR_TRANSIENT, $result['error'], self::ERROR_TTL);
    }

    /** Dev-only reset tool: drop the last recorded sync error. */
    public function clearError(): void
    {
        delete_transient(self::ERROR_TRANSIENT);
    }
}

<?php

namespace TransLangs\Admin\Rest;

use TransLangs\Discovery\InitialDiscoveryScan;
use WP_REST_Response;

/**
 * Initial-scan status + manual refresh endpoints (namespace translangs/v1).
 *
 * GET /scan/status reports running|completed|failed for the setup screen —
 * never text/term/dependency counts (arch: those are not actionable during
 * onboarding). POST /scan/refresh re-arms the SAME activation-time scan
 * (InitialDiscoveryScan) on demand, for the workspace's manual "recheck the
 * site for missing text" action; status is derived from the activation flag
 * plus the Action Scheduler batch queue either way.
 */

final class ScanRestController
{
    private const NS = 'translangs/v1';

    public function __construct(private InitialDiscoveryScan $scan)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NS, '/scan/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'status'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route(self::NS, '/scan/refresh', [
            'methods'             => 'POST',
            'callback'            => [$this, 'refresh'],
            'permission_callback' => [$this, 'canManage'],
        ]);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function status(): WP_REST_Response
    {
        return rest_ensure_response(['ok' => true, 'status' => $this->deriveStatus()]);
    }

    /**
     * Manually re-arm the same activation-time scan: set the option flag, then
     * call maybeEnqueue() directly rather than relying on the `init` hook alone
     * — a REST request has already passed `init` by the time this callback
     * runs, so waiting on the hook would delay the actual scan start until some
     * later request.
     */
    public function refresh(): WP_REST_Response
    {
        update_option(InitialDiscoveryScan::OPTION_FLAG, 1);
        $this->scan->maybeEnqueue();

        return rest_ensure_response(['ok' => true, 'status' => $this->deriveStatus()]);
    }

    private function deriveStatus(): string
    {
        // Requested but not yet enqueued into Action Scheduler (enqueued lazily on init).
        if (get_option(InitialDiscoveryScan::OPTION_FLAG)) {
            return 'running';
        }

        if (!function_exists('as_get_scheduled_actions')) {
            return 'completed';
        }

        if ($this->hasActions('pending') || $this->hasActions('in-progress')) {
            return 'running';
        }

        if ($this->hasActions('failed')) {
            return 'failed';
        }

        return 'completed';
    }

    private function hasActions(string $status): bool
    {
        $ids = as_get_scheduled_actions([
            'hook'     => InitialDiscoveryScan::HOOK,
            'group'    => InitialDiscoveryScan::GROUP,
            'status'   => $status,
            'per_page' => 1,
        ], 'ids');

        return is_array($ids) && $ids !== [];
    }
}

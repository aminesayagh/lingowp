<?php

namespace TransLangs\Admin\Rest;

use TransLangs\Discovery\InitialDiscoveryScan;
use WP_REST_Response;

/**
 * Initial-scan status endpoint (namespace translangs/v1).
 *
 * Reports only running|completed|failed for the setup screen — never text/term/
 * dependency counts or manual scan actions (arch: those are not actionable during
 * onboarding). Status is derived from the activation flag plus the Action
 * Scheduler batch queue; the scan itself (InitialDiscoveryScan) is untouched.
 */
final class ScanRestController
{
    private const NS = 'translangs/v1';

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
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function status(): WP_REST_Response
    {
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

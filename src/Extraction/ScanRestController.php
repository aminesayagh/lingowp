<?php

namespace LingoWP\Extraction;

use LingoWP\Extraction\Html\HtmlDiscoveryCrawl;
use LingoWP\Extraction\Structured\InitialDiscoveryScan;
use WP_REST_Response;

final class ScanRestController
{
    private const NS = 'lingowp/v1';

    private InitialDiscoveryScan $scan;
    private HtmlDiscoveryCrawl $htmlCrawl;

    public function __construct(
        InitialDiscoveryScan $scan,
        HtmlDiscoveryCrawl $htmlCrawl
    ) {
        $this->scan      = $scan;
        $this->htmlCrawl = $htmlCrawl;
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
        register_rest_route(self::NS, '/scan/html', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rescanHtml'],
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

    public function refresh(): WP_REST_Response
    {
        update_option(InitialDiscoveryScan::OPTION_FLAG, 1);
        $this->scan->maybeEnqueue();

        $htmlQueued = $this->htmlCrawl->start();

        return rest_ensure_response([
            'ok'          => true,
            'status'      => $this->deriveStatus(),
            'html_status' => $htmlQueued ? 'running' : 'unavailable',
        ]);
    }

    public function rescanHtml(): WP_REST_Response
    {
        return $this->queuedResponse($this->htmlCrawl->start());
    }

    private function queuedResponse(bool $queued): WP_REST_Response
    {
        return rest_ensure_response([
            'ok'     => $queued,
            'status' => $queued ? 'running' : 'unavailable',
        ]);
    }

    private function deriveStatus(): string
    {
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

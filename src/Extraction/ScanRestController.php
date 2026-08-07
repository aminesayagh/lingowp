<?php

namespace TransLangs\Extraction;

use TransLangs\Extraction\Html\HtmlDiscoveryCrawl;
use TransLangs\Extraction\Structured\InitialDiscoveryScan;
use TransLangs\Extraction\Structured\SeoDiscoveryRescan;
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

    public function __construct(
        private InitialDiscoveryScan $scan,
        private SeoDiscoveryRescan $seoRescan,
        private HtmlDiscoveryCrawl $htmlCrawl
    ) {
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
        register_rest_route(self::NS, '/scan/seo', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rescanSeo'],
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

    /**
     * Manually re-arm the same activation-time scan: set the option flag, then
     * call maybeEnqueue() directly rather than relying on the `init` hook alone
     * — a REST request has already passed `init` by the time this callback
     * runs, so waiting on the hook would delay the actual scan start until some
     * later request.
     *
     * Also fires the HTML crawl, but deliberately fire-and-forget: `status`
     * (what the caller polls) reflects only the fast DB-only scan above.
     * The crawl is network-bound and can take much longer on a large site —
     * making the dashboard wait for it would block on nothing the admin
     * actually needs to see right away. New hardcoded HTML simply appears in
     * the html:all collection whenever it's next fetched, no polling needed.
     * `html_status` rides along only as an informational hint for the
     * completion notice, never polled itself.
     */
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

    /**
     * Rescan SEO meta only — for data that arrived without firing a save hook
     * (direct-SQL importers, or SEO fields that predate Translangs).
     *
     * Separate from /scan/refresh on purpose: this is proportional to the SEO data
     * present rather than to the whole site, and it must stay available even when
     * `activated_plugin` never fires — WordPress skips that hook on silent
     * activation, so the automatic trigger alone would leave gaps.
     */
    public function rescanSeo(): WP_REST_Response
    {
        return $this->queuedResponse($this->seoRescan->start());
    }

    /**
     * Manual trigger for the background HTML crawl (sweeps every known public
     * URL for hardcoded markup). Same shape as rescanSeo() — start() itself
     * coalesces with any already-running sweep, so repeated clicks are safe.
     */
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

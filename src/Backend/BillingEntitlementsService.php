<?php

namespace LingoWP\Backend;

/**
 * Cached GET /me/entitlements result — the canonical read for all billing UI
 * state, the connection health probe (ConnectionStatus), and the Dashboard's
 * quota rail. A 60s transient cache for ordinary reads, with a bypass for the
 * one caller that needs a live value — the frontend's poll-until-settled loop
 * right after subscribe/change/capture, since the only write path for
 * entitlements is an async PayPal webhook, never the request/response of the
 * action itself.
 */
final class BillingEntitlementsService
{
    private const CACHE = 'lingowp_entitlements_cache';
    private const TTL    = 60;

    private BackendClient $backend;

    public function __construct(BackendClient $backend)
    {
        $this->backend = $backend;
    }

    /** @return array{ok:bool, status:int, data:mixed, error:string} */
    public function rawEntitlements(bool $bypassCache = false): array
    {
        if (!$bypassCache) {
            $cached = get_transient(self::CACHE);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $res = $this->backend->getEntitlements();
        set_transient(self::CACHE, $res, self::TTL);

        return $res;
    }

    /**
     * @return array{state:string, active:bool, plan:?string, period_id:?int,
     *     period_start:?string, period_end:?string, monthly_allowance:?int,
     *     monthly_remaining:?int, purchased_words:int, sites_limit:?int,
     *     sites_used:?int, over_slot:bool, pending_plan:?string,
     *     pending_approve_url:?string, pending_plan_change:?string}|null
     */
    public function data(bool $bypassCache = false): ?array
    {
        $res = $this->rawEntitlements($bypassCache);
        if (!$res['ok'] || !is_array($res['data'])) {
            return null;
        }

        $d = $res['data'];

        // 'missing_period' is an internal state value that must render
        // identically to 'inactive' — coerce once here so the frontend never
        // needs to know it exists.
        $state = (string) ($d['state'] ?? 'inactive');
        if ($state === 'missing_period') {
            $state = 'inactive';
        }

        return [
            'state'             => $state,
            'active'            => (bool) ($d['active'] ?? false),
            'plan'              => isset($d['plan']) ? (string) $d['plan'] : null,
            'period_id'         => isset($d['period_id']) ? (int) $d['period_id'] : null,
            'period_start'      => isset($d['period_start']) ? (string) $d['period_start'] : null,
            'period_end'        => isset($d['period_end']) ? (string) $d['period_end'] : null,
            'monthly_allowance' => isset($d['monthly_allowance']) ? (int) $d['monthly_allowance'] : null,
            'monthly_remaining' => isset($d['monthly_remaining']) ? (int) $d['monthly_remaining'] : null,
            'purchased_words'   => (int) ($d['purchased_words'] ?? 0),
            'sites_limit'       => isset($d['sites_limit']) ? (int) $d['sites_limit'] : null,
            'sites_used'        => isset($d['sites_used']) ? (int) $d['sites_used'] : null,
            // over_slot: true means THIS site specifically is past the
            // plan's site-slot allowance — gates the platform-AI action
            // client-side (AiTranslationController.php:29-32 confirms there's
            // no server-side pre-check today; whatever quota enforcement
            // exists belongs to /translations itself — over_slot mirrors that
            // same shape: a client-side courtesy gate, not the real check).
            'over_slot'         => (bool) ($d['over_slot'] ?? false),
            // An abandoned/incomplete PayPal checkout — the SPA offers to
            // resume it instead of re-showing the plan picker. Both null in
            // the normal case (no pending subscription).
            'pending_plan'        => isset($d['pending_plan']) ? (string) $d['pending_plan'] : null,
            'pending_approve_url' => isset($d['pending_approve_url']) ? (string) $d['pending_approve_url'] : null,
            // A different concept from pending_plan above: non-null only when
            // an *active* subscription has an approved-but-not-yet-renewed
            // tier revision (the plan_key it will grant on next renewal, not
            // what's granted today). The SPA uses this to grey out the
            // change-plan action instead of waiting for a 409.
            'pending_plan_change' => isset($d['pending_plan_change']) ? (string) $d['pending_plan_change'] : null,
        ];
    }

    /** Call after subscribe/change/capture so the next normal (cached) read is fresh. */
    public function invalidate(): void
    {
        delete_transient(self::CACHE);
    }
}

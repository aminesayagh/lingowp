<?php

namespace TransLangs\Backend;

/**
 * Cached GET /me/entitlements result — the canonical read for all billing UI
 * state. Modeled on AiQuotaService: a 60s transient cache for ordinary reads,
 * with a bypass for the one caller that needs a live value — the frontend's
 * poll-until-settled loop right after subscribe/change/capture, since the
 * only write path for entitlements is an async PayPal webhook, never the
 * request/response of the action itself.
 */
final class BillingEntitlementsService
{
    private const CACHE = 'translangs_entitlements_cache';
    private const TTL    = 60;

    public function __construct(private BackendClient $backend) {}

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
     *     monthly_remaining:?int, purchased_words:int, sites_limit:?int}|null
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
        ];
    }

    /** Call after subscribe/change/capture so the next normal (cached) read is fresh. */
    public function invalidate(): void
    {
        delete_transient(self::CACHE);
    }
}

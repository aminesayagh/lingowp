<?php

namespace LingoWP\Backend;

final class BillingEntitlementsService
{
    private const CACHE = 'lingowp_entitlements_cache';
    private const TTL    = 60;

    private BackendClient $backend;
    private ?array $memo = null;

    public function __construct(BackendClient $backend)
    {
        $this->backend = $backend;
    }

    public function rawEntitlements(bool $bypassCache = false): array
    {
        if (!$bypassCache) {
            if (is_array($this->memo)) {
                return $this->memo;
            }
            $cached = get_transient(self::CACHE);
            if (is_array($cached) && $cached['ok']) {
                return $this->memo = $cached;
            }
        }

        $res        = $this->backend->getEntitlements();
        $this->memo = $res;

        if ($res['ok']) {
            set_transient(self::CACHE, $res, self::TTL);
        } else {
            delete_transient(self::CACHE);
        }

        return $res;
    }

    public function data(bool $bypassCache = false): ?array
    {
        $res = $this->rawEntitlements($bypassCache);
        if (!$res['ok'] || !is_array($res['data'])) {
            return null;
        }

        $d = $res['data'];

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
            'over_slot'         => (bool) ($d['over_slot'] ?? false),
            'pending_plan'        => isset($d['pending_plan']) ? (string) $d['pending_plan'] : null,
            'pending_approve_url' => isset($d['pending_approve_url']) ? (string) $d['pending_approve_url'] : null,
            'pending_plan_change' => isset($d['pending_plan_change']) ? (string) $d['pending_plan_change'] : null,
        ];
    }

    public function invalidate(): void
    {
        $this->memo = null;
        delete_transient(self::CACHE);
    }
}

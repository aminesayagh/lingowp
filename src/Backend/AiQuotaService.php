<?php

namespace TransLangs\Backend;

/**
 * Cached GET /usage result — the single place that endpoint gets called from.
 * ConnectionStatus needs it for availability (ok/error), the quota card needs
 * it for the word-count fields; both read the same 60s-cached raw response
 * instead of each hitting the backend on their own, which otherwise doubles
 * the /usage traffic on every page load (they're asked for within
 * milliseconds of each other on Dashboard mount).
 */
final class AiQuotaService
{
    private const CACHE = 'translangs_usage_cache';
    private const TTL   = 60;

    public function __construct(private BackendClient $backend) {}

    /** @return array{ok:bool, status:int, data:mixed, error:string} */
    public function rawUsage(): array
    {
        $cached = get_transient(self::CACHE);
        if (is_array($cached)) {
            return $cached;
        }

        $res = $this->backend->usage();
        set_transient(self::CACHE, $res, self::TTL);

        return $res;
    }

    /** @return array{plan_name:string, word_limit:int, words_used:int, words_remaining:int, renewal_date:string}|null */
    public function data(): ?array
    {
        $res = $this->rawUsage();
        if (! $res['ok'] || ! is_array($res['data'])) {
            return null;
        }

        $d = $res['data'];

        return [
            'plan_name'       => (string) ($d['plan_name'] ?? ''),
            'word_limit'      => (int) ($d['word_limit'] ?? 0),
            'words_used'      => (int) ($d['words_used'] ?? 0),
            'words_remaining' => (int) ($d['words_remaining'] ?? 0),
            'renewal_date'    => (string) ($d['renewal_date'] ?? ''),
        ];
    }

    /** Call after any request that spends quota, so the next read re-fetches it. */
    public function invalidate(): void
    {
        delete_transient(self::CACHE);
    }
}

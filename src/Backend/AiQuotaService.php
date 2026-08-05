<?php

namespace TransLangs\Backend;

/**
 * Cached AI word-quota snapshot, shared by every endpoint that needs a
 * pre-flight check before spending backend quota (Content's /translate,
 * gettext's /translate-unit) — one transient, so a check from either place
 * sees the same 60s-fresh number.
 */
final class AiQuotaService
{
    private const CACHE = 'translangs_quota_cache';
    private const TTL   = 60;

    public function __construct(private BackendClient $backend) {}

    /** @return array{plan_name:string, word_limit:int, words_used:int, words_remaining:int, renewal_date:string}|null */
    public function data(): ?array
    {
        $cached = get_transient(self::CACHE);
        if (is_array($cached)) {
            return $cached;
        }

        $res = $this->backend->usage();
        if (! $res['ok'] || ! is_array($res['data'])) {
            return null;
        }

        $d    = $res['data'];
        $data = [
            'plan_name'       => (string) ($d['plan_name'] ?? ''),
            'word_limit'      => (int) ($d['word_limit'] ?? 0),
            'words_used'      => (int) ($d['words_used'] ?? 0),
            'words_remaining' => (int) ($d['words_remaining'] ?? 0),
            'renewal_date'    => (string) ($d['renewal_date'] ?? ''),
        ];
        set_transient(self::CACHE, $data, self::TTL);

        return $data;
    }

    /** Call after any request that spends quota, so the next data() re-reads it. */
    public function invalidate(): void
    {
        delete_transient(self::CACHE);
    }
}

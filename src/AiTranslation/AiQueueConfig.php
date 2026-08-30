<?php

namespace LingoWP\AiTranslation;

/** Constants for the async translate-all queue. See AiTaskSubmitter, AiResultCollector, CollectionBoard::presentText(). */
final class AiQueueConfig
{
    private function __construct() {}

    /** Client-side chunk size for one /translate submit POST -- the SPA
     * re-POSTs (via the `more` flag) until a scope's whole backlog is
     * queued. Independent of the backend's own per-request caps. */
    public const MAX_TEXTS_PER_SUBMIT = 500;

    /** How long ai_pending_since is trusted as "still waiting" before a row
     * silently reverts to its prior status in presentText(). */
    public const PENDING_TTL_HOURS = 6;

    /** True when $pendingSince (MySQL datetime, site-local per current_time('mysql')) is set and newer than PENDING_TTL_HOURS. */
    public static function isFreshPending(?string $pendingSince, int $nowTimestamp): bool
    {
        if ($pendingSince === null || $pendingSince === '') {
            return false;
        }
        $since = strtotime($pendingSince);
        if ($since === false) {
            return false;
        }

        // 3600, not WordPress's HOUR_IN_SECONDS -- kept dependency-free so
        // this pure function is testable in the no-WordPress check tier.
        return $since > $nowTimestamp - (self::PENDING_TTL_HOURS * 3600);
    }
}

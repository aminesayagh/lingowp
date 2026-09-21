<?php

namespace LingoWP\AiTranslation;

final class AiQueueConfig
{
    private function __construct() {}

    public const MAX_TEXTS_PER_SUBMIT = 500;

    public const PENDING_TTL_HOURS = 6;

    public static function isFreshPending(?string $pendingSince, int $nowTimestamp): bool
    {
        if ($pendingSince === null || $pendingSince === '') {
            return false;
        }
        $since = strtotime($pendingSince);
        if ($since === false) {
            return false;
        }

        return $since > $nowTimestamp - (self::PENDING_TTL_HOURS * 3600);
    }
}

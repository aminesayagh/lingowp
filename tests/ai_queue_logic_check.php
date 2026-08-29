<?php

/**
 * Standalone check for AiQueueConfig::isFreshPending() — no WordPress, no DB.
 * Run: php tests/ai_queue_logic_check.php
 *
 * This is the whole "spinner or not" decision for a pending unit
 * (CollectionBoard::presentText()), so its TTL boundary is asserted exactly.
 */

require __DIR__ . '/../vendor/autoload.php';

use LingoWP\AiTranslation\AiQueueConfig;

$failed = 0;
$total  = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $failed, $total;
    $total++;
    if ($cond) {
        echo "PASS  $label\n";
        return;
    }
    $failed++;
    echo "FAIL  $label\n";
    if ($detail !== '') {
        echo "        $detail\n";
    }
}

$now = strtotime('2026-01-01 12:00:00');
$ttlSeconds = AiQueueConfig::PENDING_TTL_HOURS * 3600;

ok('null pending_since is never fresh', AiQueueConfig::isFreshPending(null, $now) === false);
ok('empty string pending_since is never fresh', AiQueueConfig::isFreshPending('', $now) === false);
ok('unparseable pending_since is never fresh', AiQueueConfig::isFreshPending('not-a-date', $now) === false);

ok('just stamped is fresh', AiQueueConfig::isFreshPending(date('Y-m-d H:i:s', $now), $now) === true);

ok('one second before the TTL cutoff is still fresh',
    AiQueueConfig::isFreshPending(date('Y-m-d H:i:s', $now - $ttlSeconds + 1), $now) === true);

ok('exactly at the TTL cutoff is stale',
    AiQueueConfig::isFreshPending(date('Y-m-d H:i:s', $now - $ttlSeconds), $now) === false);

ok('well past the TTL cutoff is stale',
    AiQueueConfig::isFreshPending(date('Y-m-d H:i:s', $now - $ttlSeconds - 3600), $now) === false);

echo $failed === 0
    ? "\nAll $total assertions passed.\n"
    : "\n$failed of $total assertion(s) failed.\n";

exit($failed === 0 ? 0 : 1);

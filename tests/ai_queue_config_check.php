<?php

/**
 * Standalone check for AiQueueConfig — no WordPress, no DB.
 * Run: php tests/ai_queue_config_check.php
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

ok('MAX_TEXTS_PER_SUBMIT is positive', AiQueueConfig::MAX_TEXTS_PER_SUBMIT > 0);
ok('PENDING_TTL_HOURS is positive', AiQueueConfig::PENDING_TTL_HOURS > 0);
ok('PULL_RATE_LIMIT_SECONDS is positive', AiQueueConfig::PULL_RATE_LIMIT_SECONDS > 0);

echo $failed === 0
    ? "\nAll $total assertions passed.\n"
    : "\n$failed of $total assertion(s) failed.\n";

exit($failed === 0 ? 0 : 1);

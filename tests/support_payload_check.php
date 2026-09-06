<?php

/**
 * Standalone check for SupportRestController's payload gate and wire format —
 * no WP, no DB. Run: php tests/support_payload_check.php
 * Exits non-zero if any case fails.
 *
 * The controller calls WordPress's is_email() unqualified, so PHP resolves it
 * against its own namespace first: declaring is_email() in LingoWP\Support
 * below stands in for core without loading WordPress. The stub is deliberately
 * cruder than core's — this file pins the controller's branches, not core's
 * address parser.
 */

namespace LingoWP\Support;

function is_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

namespace LingoWP\Tests;

use LingoWP\Support\SupportRestController;

require __DIR__ . '/../vendor/autoload.php';

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
    echo "FAIL  $label" . ($detail !== '' ? "  ($detail)" : '') . "\n";
}

$reason = static fn(string $e, string $m): string => SupportRestController::rejectionReason($e, $m);

// --- accepted ------------------------------------------------------------
ok('ordinary message passes', $reason('admin@example.com', 'The board is empty.') === '');
ok('message exactly at the 2000-char cap passes', $reason('admin@example.com', str_repeat('a', 2000)) === '');
ok('multibyte message counted in characters, not bytes', $reason('admin@example.com', str_repeat('é', 2000)) === '');

// --- rejected ------------------------------------------------------------
ok('empty message rejected', $reason('admin@example.com', '') === 'ERR_INVALID_PAYLOAD');
ok('one char over the cap rejected', $reason('admin@example.com', str_repeat('a', 2001)) === 'ERR_INVALID_PAYLOAD');
ok('multibyte over the cap rejected', $reason('admin@example.com', str_repeat('é', 2001)) === 'ERR_INVALID_PAYLOAD');
ok('empty email rejected', $reason('', 'Help please.') === 'ERR_INVALID_PAYLOAD');
ok('malformed email rejected', $reason('admin@', 'Help please.') === 'ERR_INVALID_PAYLOAD');

// --- wire format ---------------------------------------------------------
// The backend receives one string on the shared /support/suggestions route, so
// the "support_request" tag is what separates a human report from the
// "feature_interest:*" codes the notify buttons send through the same route.
$composed = SupportRestController::composeMessage('admin@example.com', "Line one\nLine two");
ok('composed message carries the sender', strpos($composed, 'admin@example.com') !== false, $composed);
ok('composed message is tagged support_request', strncmp($composed, 'support_request ', 16) === 0, $composed);
ok('composed message keeps the body verbatim', substr($composed, -strlen("Line one\nLine two")) === "Line one\nLine two", $composed);

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);

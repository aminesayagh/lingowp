<?php

/**
 * Standalone check for the gettext async "Translate All" queue's two pure
 * pieces — no framework, no DB.
 * Run: php tests/gettext_ai_hash_check.php
 *
 * 1. The queue must key incoming backend results by a NORMALIZED hash
 *    (TranslationKey::currentHash — the same hash the backend echoes as
 *    source_hash), never by gettext's own byte-exact unit_hash. Mixing the
 *    two up would mean every async result silently fails to match any row.
 * 2. The ai_pending_locales pipe-list helpers (GettextAiQueue::addLocale /
 *    removeLocale / hasLocale) — the "which locales are in flight for this
 *    row" bookkeeping lives as a plain string on one row, not extra rows.
 */

require __DIR__ . '/../src/Shared/Text/StringNormalizer.php';
require __DIR__ . '/../src/Shared/Text/TranslationKey.php';
require __DIR__ . '/../src/GettextDomains/GettextUnitRepository.php';
require __DIR__ . '/../src/GettextDomains/GettextAiQueue.php';

use LingoWP\GettextDomains\GettextAiQueue;
use LingoWP\GettextDomains\GettextUnitRepository;
use LingoWP\Shared\Text\TranslationKey;

$failed = 0;

function check(string $label, $got, $expected): void
{
    global $failed;
    if ($got === $expected) {
        echo "ok    $label\n";
        return;
    }
    $failed++;
    echo "FAIL  $label\n";
    echo "        expected: " . json_encode($expected) . "\n";
    echo "        got:      " . json_encode($got) . "\n";
}

// ── norm_hash: normalized, matches what the backend echoes as source_hash ──

$normHex = static fn (string $text): string => bin2hex(TranslationKey::currentHash($text));

check('norm_hash folds case ("Save" == "save")', $normHex('Save'), $normHex('save'));
check('norm_hash is 64 lowercase hex chars (sha256)', (bool) preg_match('/^[0-9a-f]{64}$/', $normHex('Save')), true);

// ── norm_hash is NOT unit_hash: the two hashes must never be confused ──────

check(
    'norm_hash differs from the byte-exact unit_hash',
    $normHex('Save') !== GettextUnitRepository::hashHex('Save', null),
    true
);

// ── ai_pending_locales pipe-list helpers ───────────────────────────────────

check('addLocale on empty list', GettextAiQueue::addLocale('', 'fr_FR'), 'fr_FR');
check('addLocale appends', GettextAiQueue::addLocale('fr_FR', 'en_GB'), 'fr_FR|en_GB');
check('addLocale is idempotent (already present)', GettextAiQueue::addLocale('fr_FR|en_GB', 'fr_FR'), 'fr_FR|en_GB');

check('hasLocale finds a member', GettextAiQueue::hasLocale('fr_FR|en_GB', 'en_GB'), true);
check('hasLocale rejects a non-member', GettextAiQueue::hasLocale('fr_FR|en_GB', 'de_DE'), false);
check('hasLocale does not prefix-match ("en" vs "en_GB")', GettextAiQueue::hasLocale('en_GB', 'en'), false);
check('hasLocale on an empty list', GettextAiQueue::hasLocale('', 'fr_FR'), false);

check('removeLocale drops the only entry', GettextAiQueue::removeLocale('fr_FR', 'fr_FR'), '');
check('removeLocale drops one of several, no stray pipes', GettextAiQueue::removeLocale('fr_FR|en_GB|de_DE', 'en_GB'), 'fr_FR|de_DE');
check('removeLocale on a non-member is a no-op', GettextAiQueue::removeLocale('fr_FR|en_GB', 'de_DE'), 'fr_FR|en_GB');
check('removeLocale on an empty list', GettextAiQueue::removeLocale('', 'fr_FR'), '');

echo $failed === 0 ? "\nAll cases passed.\n" : "\n$failed case(s) failed.\n";

exit($failed === 0 ? 0 : 1);

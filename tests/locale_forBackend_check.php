<?php

/**
 * Standalone check for LocaleNormalizer::forBackend() — no framework.
 * Run: php tests/locale_forBackend_check.php
 * Exits non-zero if any case fails.
 */

require __DIR__ . '/../src/Language/Domain/LocaleNormalizer.php';

use TransLangs\Language\Domain\LocaleNormalizer;

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

check('fr_FR -> fr-fr', LocaleNormalizer::forBackend('fr_FR'), 'fr-fr');
check('en_US -> en-us', LocaleNormalizer::forBackend('en_US'), 'en-us');
check('ar -> ar (no region)', LocaleNormalizer::forBackend('ar'), 'ar');
check('round-trip via normalize()', LocaleNormalizer::forBackend(LocaleNormalizer::normalize('EN-US')), 'en-us');

echo $failed === 0 ? "\nAll cases passed.\n" : "\n$failed case(s) failed.\n";

exit($failed === 0 ? 0 : 1);

<?php

/**
 * Checklist §21 (traps and edge cases) — automated coverage for what was
 * verified by hand this session via ZQTRAP sentinels + a clean HTML fixture
 * file (a bash-quoting artifact in the first ad-hoc test produced a false
 * positive for <script> leaking through — re-verified via a clean file, see
 * [[lingowp-shortcode-guard-fix]] memory note).
 * Run: wp eval-file tests/db/section21_traps_it.php
 *
 * Most of §21 (placeholders, inline HTML, unclosed tags, comments, entities,
 * emoji, URLs/emails mid-sentence, number/whitespace/punctuation-only strings,
 * long strings, <!--more-->, CPT deactivate/reactivate, edit/delete/trash
 * re-detection) was already covered by hand and by earlier sections' own test
 * files (reconcile_it.php's carry-forward, section1's trash-visibility test).
 * This file covers only what's NEW: the two fixes shipped from this section's
 * findings, plus the subtle whitespace/case design choices worth pinning so a
 * future refactor can't silently change them.
 */

require __DIR__ . '/_harness.php';

use LingoWP\Shared\Parsing\TranslationSubmissionGuard;
use LingoWP\Shared\Parsing\ShortcodeGuard;
use LingoWP\Shared\Source\PostMetaValuePaths;
use LingoWP\Shared\Text\TranslationKey;

test_guard();
// No test_begin(): this file only reads/calls pure logic, writes nothing to the DB.

// ── 1. Shortcode protection ──────────────────────────────────────────────────

test_section('shortcode protection (checklist §21 finding, fixed):');

if (!shortcode_exists('lingowp_test_sc')) {
    add_shortcode('lingowp_test_sc', static fn(): string => '');
}

$withShortcode = 'Book a [lingowp_test_sc id="5"] today';

$droppedErrors = TranslationSubmissionGuard::validate($withShortcode, 'Reservez aujourd\'hui');
test_ok('a translation that drops the shortcode entirely is flagged',
    in_array('missing_shortcode:lingowp_test_sc', $droppedErrors, true),
    'got ' . json_encode($droppedErrors));

$editedAttrs = TranslationSubmissionGuard::validate($withShortcode, 'Reservez un [lingowp_test_sc id="5" title="Contact"] aujourd\'hui');
test_ok('editing the shortcode\'s own attributes stays allowed (structure, not byte-content)',
    $editedAttrs === [], 'got ' . json_encode($editedAttrs));

$unregistered = TranslationSubmissionGuard::validate('See [1] for details', 'Voir les details');
test_ok('bracketed text that is NOT a registered shortcode is never flagged',
    $unregistered === [], 'got ' . json_encode($unregistered));

$addedErrors = ShortcodeGuard::validate('No shortcode here', 'Now with [lingowp_test_sc]');
test_ok('a translation that ADDS a shortcode not in the source is flagged',
    in_array('extra_shortcode:lingowp_test_sc', $addedErrors, true),
    'got ' . json_encode($addedErrors));

// ── 2. Object-shaped meta values ─────────────────────────────────────────────

test_section('object-shaped meta values (checklist §21 finding, fixed):');

$objValue = (object) ['field' => 'LINGOWP_TEST object value text'];
$objLeaves = PostMetaValuePaths::extract($objValue);
test_ok('a plain object value is discovered, same as an array would be',
    $objLeaves === [['context' => 'field', 'text' => 'LINGOWP_TEST object value text']],
    'got ' . json_encode($objLeaves));

$nestedInArray = ['slides' => (object) ['title' => 'LINGOWP_TEST nested object']];
test_ok('an object nested inside an array is walked correctly',
    PostMetaValuePaths::extract($nestedInArray) === [['context' => 'slides.title', 'text' => 'LINGOWP_TEST nested object']]);

$nestedInObject = (object) ['slides' => ['title' => 'LINGOWP_TEST nested array']];
test_ok('an array nested inside an object is walked correctly',
    PostMetaValuePaths::extract($nestedInObject) === [['context' => 'slides.title', 'text' => 'LINGOWP_TEST nested array']]);

$patched = PostMetaValuePaths::patch($objValue, ['field' => 'LINGOWP_TEST translated']);
test_ok('patch() preserves object shape, not just array', is_object($patched));
test_ok('patch() applies the translation', $patched->field === 'LINGOWP_TEST translated');

// Regression: array-shaped repeater (the case that already worked) is unchanged.
$arrRepeater = ['slides' => [['title' => 'LINGOWP_TEST row one'], ['title' => 'LINGOWP_TEST row two']]];
$arrLeaves = PostMetaValuePaths::extract($arrRepeater);
test_ok('array-shaped repeater still extracts identically (no regression)',
    $arrLeaves === [
        ['context' => 'slides.0.title', 'text' => 'LINGOWP_TEST row one'],
        ['context' => 'slides.1.title', 'text' => 'LINGOWP_TEST row two'],
    ], 'got ' . json_encode($arrLeaves));

// ── 3. Whitespace/case hashing — deliberate design, pinned so it can't silently change ─

test_section('whitespace/case hashing (deliberate design, not a bug):');

$plain  = TranslationKey::currentHash('Hello world');
$trail  = TranslationKey::currentHash('Hello world ');
$upper  = TranslationKey::currentHash('HELLO WORLD');
test_ok('trailing whitespace hashes the same as trimmed text (by design)', $plain === $trail);
test_ok('case differences hash the same (by design, case-insensitive matching)', $plain === $upper);

$withNbsp  = TranslationKey::currentHash("text\xc2\xa0here");
$withSpace = TranslationKey::currentHash('text here');
test_ok('a non-breaking space is a genuinely different character, hashes distinctly',
    $withNbsp !== $withSpace);

test_done();

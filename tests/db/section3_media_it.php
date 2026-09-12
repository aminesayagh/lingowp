<?php

/**
 * Checklist §3 (core media) — automated coverage for what was verified by hand
 * this session via ZQMED01-ZQMED09 sentinels, including the real attachment-
 * discovery bug found and fixed the same day.
 * Run: wp eval-file tests/db/section3_media_it.php
 *
 * Deliberate exception to this suite's usual "never wp_insert_post(), it fires
 * hooks" convention (see reconcile_it.php / board_search_it.php): the bug this
 * file regression-tests lived IN the hook wiring itself (WordPress core skips
 * wp_after_insert_post for post_type=attachment, firing add_attachment /
 * edit_attachment instead — see SourceLifecycleRefresher's class doc), so only
 * firing the real hooks can catch it coming back. By the time `wp eval-file`
 * runs, plugins_loaded has already fired, so the real, production
 * SourceLifecycleRefresher is already registered exactly as on a live request.
 *
 * SVG pruning and gallery captions are pure HtmlUnitExtractor behavior, covered
 * in tests/split_field_check.php — not duplicated here as a DB test.
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Shared\Source\TranslationStatus;

global $wpdb;

test_guard();
test_begin();

$parents    = TranslationMemorySchema::parentTables();
$PP         = $parents['post'];
$MP         = $parents['meta'];
$discovered = TranslationStatus::SOURCE_DISCOVERED;

$fieldText = static function (int $postId, string $field) use ($PP, $discovered) {
    return test_var("SELECT original_text FROM {$PP} WHERE post_id = %d AND field = %s AND status = %d", $postId, $field, $discovered);
};

// ── 1. Attachment title/caption/description — the bug regression test ───────

test_section('attachment title/caption/description (regression: real hooks now fire):');

$attId = wp_insert_attachment([
    'post_title'     => 'LINGOWP_TEST attachment title',
    'post_excerpt'   => 'LINGOWP_TEST attachment caption',
    'post_content'   => '<p>LINGOWP_TEST attachment description</p>',
    'post_status'    => 'inherit',
    'post_mime_type' => 'image/jpeg',
], '/tmp/lingowp-test-nonexistent.jpg');

test_ok('attachment created', $attId > 0, "att_id=$attId");
test_ok('title discovered via add_attachment (was silently broken before the fix)',
    $fieldText($attId, 'post_title') === 'LINGOWP_TEST attachment title');
test_ok('caption (post_excerpt) discovered via add_attachment',
    $fieldText($attId, 'post_excerpt') === 'LINGOWP_TEST attachment caption');
test_ok('description (post_content) discovered via add_attachment',
    $fieldText($attId, 'post_content') === 'LINGOWP_TEST attachment description');

// edit_attachment path: a later rename must be picked up too, not just the
// initial insert — this is the distinct hook WordPress fires for an UPDATE.
wp_update_post(['ID' => $attId, 'post_title' => 'LINGOWP_TEST attachment title edited']);
test_ok('renaming an existing attachment is discovered via edit_attachment',
    $fieldText($attId, 'post_title') === 'LINGOWP_TEST attachment title edited');
test_ok('the stale title row is gone, not left alongside the new one',
    test_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND field = 'post_title' AND status = %d", $attId, $discovered) === 1);

// ── 2. Non-image mime type: the fix is about post_type, not image mime types ─

test_section('non-image attachment (PDF) title:');

$pdfId = wp_insert_attachment([
    'post_title'     => 'LINGOWP_TEST pdf title',
    'post_status'    => 'inherit',
    'post_mime_type' => 'application/pdf',
], '/tmp/lingowp-test-nonexistent.pdf');

test_ok('PDF attachment title discovered the same way as an image attachment',
    $fieldText($pdfId, 'post_title') === 'LINGOWP_TEST pdf title');

// ── 3. Attachment ALT text — was never broken (separate post-meta hooks) ────

test_section('attachment alt text (sanity check, this path was always fine):');

update_post_meta($attId, '_wp_attachment_image_alt', 'LINGOWP_TEST alt text');
test_ok('alt text discovered via the post-meta hooks',
    test_var("SELECT original_text FROM {$MP} WHERE post_id = %d AND meta_key = '_wp_attachment_image_alt' AND status = %d", $attId, $discovered)
        === 'LINGOWP_TEST alt text');

test_done();

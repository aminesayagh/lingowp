<?php

/**
 * markPendingBatch() / applyQueueResult() / saveTranslation()'s ai_pending_since
 * and ai_error handling, against real hash-link (post) and parent_id (meta)
 * tables. Run: wp eval-file tests/db/ai_pending_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Database\Repository\WpDbBoardQueries;
use LingoWP\Shared\Source\Type\PostSource;
use LingoWP\Shared\Source\Type\PostMetaSource;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Shared\Text\TranslationKey;
use LingoWP\Database\TranslationMemorySchema;

global $wpdb;

test_guard();
test_begin();

$repo    = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
$parents = TranslationMemorySchema::parentTables();
$PP      = $parents['post'];
$PC      = $PP . '_translation';
$MP      = $parents['meta'];
$MC      = $MP . '_translation';
$LANG    = 'fr';
$discovered = TranslationStatus::SOURCE_DISCOVERED;

$now = current_time('mysql');
$postId = test_insert($wpdb->posts, [
    'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => $now,
    'post_content' => '', 'post_title' => 'LINGOWP_TEST AI Queue Fixture',
    'post_excerpt' => '', 'post_status' => 'publish', 'comment_status' => 'closed',
    'ping_status' => 'closed', 'post_name' => 'lingowp-test-ai-queue-fixture',
    'post_modified' => $now, 'post_modified_gmt' => $now, 'post_type' => 'post',
]);
test_ok('fixture post created', $postId > 0, "post_id=$postId");

$repo->upsertSources([
    new PostSource($postId, 'post_title', 'LINGOWP_TEST AI Queue Title'),
    new PostMetaSource($postId, 'lingowp_test_meta', null, 'LINGOWP_TEST AI Queue Meta'),
]);

$postRowId = (int) test_var(
    "SELECT id FROM {$PP} WHERE post_id = %d AND field = 'post_title' AND status = %d",
    $postId, $discovered
);
$metaRowId = (int) test_var(
    "SELECT id FROM {$MP} WHERE post_id = %d AND meta_key = 'lingowp_test_meta' AND status = %d",
    $postId, $discovered
);
test_ok('post fixture row exists', $postRowId > 0, "id=$postRowId");
test_ok('meta fixture row exists', $metaRowId > 0, "id=$metaRowId");

$postRef = SourceRef::make('post', $postRowId);
$metaRef = SourceRef::make('meta', $metaRowId);
$postHashHex = strtolower(bin2hex(TranslationKey::currentHash('LINGOWP_TEST AI Queue Title')));

// ── 1. markPendingBatch stamps a fresh pending row on both child shapes ─────

test_section('markPendingBatch:');

$repo->markPendingBatch([$postRef, $metaRef], [$LANG]);

$postPending = test_var(
    "SELECT ai_pending_since FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = %s",
    $postHashHex, $LANG
);
test_ok('post child row got ai_pending_since', $postPending !== null, var_export($postPending, true));
test_ok('post child row has no translation yet',
    test_var("SELECT translated_text FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $postHashHex, $LANG) === null);

$metaPending = test_var(
    "SELECT ai_pending_since FROM {$MC} WHERE parent_id = %d AND lang_code = %s", $metaRowId, $LANG
);
test_ok('meta child row got ai_pending_since', $metaPending !== null, var_export($metaPending, true));

// ── 2. Re-marking an existing needs_review row keeps its text ──────────────

test_section('markPendingBatch on an existing translation:');

$repo->saveTranslation($postRef, $LANG, 'Ancien texte', TranslationStatus::NEEDS_REVIEW, 'manual');
test_ok('ai_pending_since cleared by a manual save',
    test_var("SELECT ai_pending_since FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $postHashHex, $LANG) === null);

$repo->markPendingBatch([$postRef], [$LANG]);
test_ok('re-stamped pending on an existing row',
    test_var("SELECT ai_pending_since FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $postHashHex, $LANG) !== null);
test_ok('old translated_text survives a pending re-stamp',
    test_var("SELECT translated_text FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $postHashHex, $LANG) === 'Ancien texte');
test_ok('old status survives a pending re-stamp',
    (int) test_var("SELECT status FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $postHashHex, $LANG) === TranslationStatus::NEEDS_REVIEW);

// ── 3. applyQueueResult success clears pending and writes the text ─────────

test_section('applyQueueResult success:');

$repo->applyQueueResult($postHashHex, $LANG, 'Nouveau texte AI', null);
test_ok('ai_pending_since cleared on success',
    test_var("SELECT ai_pending_since FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $postHashHex, $LANG) === null);
test_ok('ai_error cleared on success',
    test_var("SELECT ai_error FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $postHashHex, $LANG) === null);
test_ok('translated_text written',
    test_var("SELECT translated_text FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $postHashHex, $LANG) === 'Nouveau texte AI');
test_ok('provider is ai',
    test_var("SELECT provider FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $postHashHex, $LANG) === 'ai');
test_ok('status is needs_review',
    (int) test_var("SELECT status FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $postHashHex, $LANG) === TranslationStatus::NEEDS_REVIEW);

// ── 4. applyQueueResult failure sets ai_error, leaves text/status alone ─────

test_section('applyQueueResult failure:');

$repo->markPendingBatch([$metaRef], [$LANG]);
$repo->applyQueueResult('deadbeef00000000000000000000000000000000000000000000000000dead', $LANG, null, 'ERR_BACKEND');
// The failure hash doesn't match any parent, so nothing should be touched --
// this just proves applyQueueResult() no-ops safely on an unknown hash.
test_ok('unknown hash leaves the meta row alone',
    (string) test_var("SELECT ai_pending_since FROM {$MC} WHERE parent_id = %d AND lang_code = %s", $metaRowId, $LANG) !== '');

$repo->applyQueueResult(
    strtolower(bin2hex(TranslationKey::currentHash('LINGOWP_TEST AI Queue Meta'))),
    $LANG, null, 'ERR_BACKEND_FAILED'
);
test_ok('meta ai_error set on failure',
    test_var("SELECT ai_error FROM {$MC} WHERE parent_id = %d AND lang_code = %s", $metaRowId, $LANG) === 'ERR_BACKEND_FAILED');
test_ok('meta ai_pending_since cleared on failure',
    test_var("SELECT ai_pending_since FROM {$MC} WHERE parent_id = %d AND lang_code = %s", $metaRowId, $LANG) === null);
test_ok('meta translated_text still untouched by the failure',
    test_var("SELECT translated_text FROM {$MC} WHERE parent_id = %d AND lang_code = %s", $metaRowId, $LANG) === null);

test_done();

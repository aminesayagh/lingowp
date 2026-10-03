<?php

/**
 * Full stamp -> submit(mocked backend) -> pull -> apply -> board-read cycle
 * for the async translate-all queue. Run: wp eval-file tests/db/ai_result_applier_it.php
 *
 * The backend is faked (implements TranslationBackendPort) -- no HTTP, no
 * WordPress HTTP API involved, since AiTaskSubmitter/AiResultCollector both
 * depend on the interface, not BackendClient.
 */

require __DIR__ . '/_harness.php';

use LingoWP\AiTranslation\AiResultCollector;
use LingoWP\AiTranslation\AiTaskSubmitter;
use LingoWP\Backend\TranslationBackendPort;
use LingoWP\Board\CollectionBoard;
use LingoWP\Database\Repository\WpDbBoardQueries;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Shared\Source\Type\OptionSource;
use LingoWP\Shared\Source\Type\PostMetaSource;
use LingoWP\Shared\Source\Type\PostSource;
use LingoWP\Shared\Text\TranslationKey;
use LingoWP\Database\TranslationMemorySchema;

final class FakeBackendPort implements TranslationBackendPort
{
    /** @var array<int, array{source_hash:string, target_lang:string, output:?string, error:?string}> */
    public array $pending = [];
    public array $submitted = [];
    public array $acked = [];

    public function submitTasks(array $payload): array
    {
        $this->submitted[] = $payload;
        return ['ok' => true, 'status' => 202, 'data' => ['queued' => count($payload['texts']), 'skipped_no_words' => 0], 'error' => null];
    }

    public function pullResults(int $cursor = 0): array
    {
        return ['ok' => true, 'status' => 200, 'data' => ['results' => $this->pending, 'next_cursor' => 0, 'has_more' => false], 'error' => null];
    }

    public function ackResults(array $items): array
    {
        $this->acked = array_merge($this->acked, $items);
        return ['ok' => true, 'status' => 200, 'data' => ['deleted' => count($items)], 'error' => null];
    }
}

global $wpdb;

test_guard();
test_begin();

$repo    = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
$board   = new CollectionBoard($repo, new OptionLanguageRegistry());
$backend = new FakeBackendPort();
$submitter = new AiTaskSubmitter($backend, $repo, new OptionLanguageRegistry(), new LanguageMetadataStore());
$collector = new AiResultCollector($backend, $repo);

$parents = TranslationMemorySchema::parentTables();
$PP = $parents['post'];
$PC = $PP . '_translation';
$MP = $parents['meta'];
$MC = $MP . '_translation';
$OP = $parents['option'];
$OC = $OP . '_translation';
$LANG = 'fr';
$discovered = TranslationStatus::SOURCE_DISCOVERED;
$hex = static fn (string $t): string => strtolower(bin2hex(TranslationKey::currentHash($t)));

$now = current_time('mysql');
$postId = test_insert($wpdb->posts, [
    'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => $now,
    'post_content' => '', 'post_title' => 'LINGOWP_TEST AI Applier Fixture',
    'post_excerpt' => '', 'post_status' => 'publish', 'comment_status' => 'closed',
    'ping_status' => 'closed', 'post_name' => 'lingowp-test-ai-applier-fixture',
    'post_modified' => $now, 'post_modified_gmt' => $now, 'post_type' => 'post',
]);
test_ok('fixture post created', $postId > 0, "post_id=$postId");

// A plain unit (goes through cleanly), a marker unit (will be guard-rejected),
// and an option value sharing the plain unit's exact text (two parent tables,
// one hash -- proves applyQueueResult() fans out across table types).
$PLAIN_TEXT  = 'LINGOWP_TEST Shared Across Tables';
$MARKER_TEXT = 'LINGOWP_TEST Hello {1}world{/1}';

// The option parent table FKs option_id -> wp_options.option_id, so this
// needs a real option row, not an arbitrary id.
update_option('lingowp_test_fake_option', $PLAIN_TEXT);
$realOptionId = (int) test_var(
    "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", 'lingowp_test_fake_option'
);
test_ok('fake wp_options row exists', $realOptionId > 0);

$repo->upsertSources([
    new PostSource($postId, 'post_title', $PLAIN_TEXT),
    new PostMetaSource($postId, 'lingowp_test_meta', null, $MARKER_TEXT),
    // Shares the post title's exact text -- a second parent table for the
    // same hash, proving applyQueueResult()'s cross-table fan-out.
    new OptionSource($realOptionId, 'lingowp_test_fake_option', '', $PLAIN_TEXT),
]);

$postRowId   = (int) test_var("SELECT id FROM {$PP} WHERE post_id = %d AND field = 'post_title' AND status = %d", $postId, $discovered);
$metaRowId   = (int) test_var("SELECT id FROM {$MP} WHERE post_id = %d AND meta_key = 'lingowp_test_meta' AND status = %d", $postId, $discovered);
$optionRowId = (int) test_var("SELECT id FROM {$OP} WHERE option_id = %d AND status = %d", $realOptionId, $discovered);
test_ok('post fixture row exists', $postRowId > 0);
test_ok('meta fixture row exists', $metaRowId > 0);
test_ok('fake option fixture row created', $optionRowId > 0);

// ── 1. Submit stamps ai_pending_since via the fake backend ──────────────────

test_section('AiTaskSubmitter::submit:');

$units = [
    ['ref' => SourceRef::make('post', $postRowId), 'original' => $PLAIN_TEXT, 'group_id' => "content:{$postId}"],
    ['ref' => SourceRef::make('meta', $metaRowId), 'original' => $MARKER_TEXT, 'group_id' => "content:{$postId}"],
];
$result = $submitter->submit($units, [$LANG]);
test_ok('submit reports no error', $result['error'] === null, var_export($result['error'], true));
test_ok('backend received the submit payload', count($backend->submitted) === 1);

$plainHash  = $hex($PLAIN_TEXT);
$markerHash = $hex($MARKER_TEXT);

test_ok('post child row is pending',
    test_var("SELECT ai_pending_since FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $plainHash, $LANG) !== null);
test_ok('meta child row is pending',
    test_var("SELECT ai_pending_since FROM {$MC} WHERE parent_id = %d AND lang_code = %s", $metaRowId, $LANG) !== null);

// ── 2. Pull + apply: one success, one guard-rejected, one backend error ────

test_section('AiResultCollector::collectOnce:');

$backend->pending = [
    ['source_hash' => $plainHash, 'target_lang' => $LANG, 'output' => 'Texte partage', 'error' => null],
    ['source_hash' => $markerHash, 'target_lang' => $LANG, 'output' => 'Bonjour monde', 'error' => null], // drops {1}..{/1}
];
$collector->collectOnce();

test_ok('both results acked', count($backend->acked) === 2);

// Success: applies to BOTH the post AND the unrelated option row sharing the hash.
test_ok('post translated_text written',
    test_var("SELECT translated_text FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $plainHash, $LANG) === 'Texte partage');
test_ok('post ai_pending_since cleared',
    test_var("SELECT ai_pending_since FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $plainHash, $LANG) === null);
test_ok('option row (different table, same hash) also got the translation',
    test_var("SELECT translated_text FROM {$OC} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $plainHash, $LANG) === 'Texte partage');

// Guard-rejected: text untouched, ai_error set.
test_ok('meta translated_text untouched by the guard rejection',
    test_var("SELECT translated_text FROM {$MC} WHERE parent_id = %d AND lang_code = %s", $metaRowId, $LANG) === null);
test_ok('meta ai_error set to the protected-structure code',
    test_var("SELECT ai_error FROM {$MC} WHERE parent_id = %d AND lang_code = %s", $metaRowId, $LANG) === 'ERR_PROTECTED_TOKEN_STRUCTURE');
test_ok('meta ai_pending_since cleared even on rejection',
    test_var("SELECT ai_pending_since FROM {$MC} WHERE parent_id = %d AND lang_code = %s", $metaRowId, $LANG) === null);

// ── 3. Board read reflects both outcomes ────────────────────────────────────

test_section('CollectionBoard reflects the applied results:');

$postTexts = $board->collectionTexts("content:{$postId}", $LANG, '', [], false);
$titleUnit = null;
$metaUnit  = null;
foreach ((array) $postTexts as $t) {
    if ($t['id'] === SourceRef::make('post', $postRowId)->format()) {
        $titleUnit = $t;
    }
    if ($t['id'] === SourceRef::make('meta', $metaRowId)->format()) {
        $metaUnit = $t;
    }
}
test_ok('title unit found on the board', $titleUnit !== null);
test_ok('title unit status is needs_review (AI-written, unreviewed)', $titleUnit !== null && $titleUnit['status'] === 'needs_review');
test_ok('meta unit found on the board', $metaUnit !== null);
test_ok('meta unit status reverted to missing (no text, pending cleared)', $metaUnit !== null && $metaUnit['status'] === 'missing');
test_ok('meta unit surfaces the ai_error', $metaUnit !== null && $metaUnit['ai_error'] === 'ERR_PROTECTED_TOKEN_STRUCTURE');

test_done();

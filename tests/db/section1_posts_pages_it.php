<?php

/**
 * Checklist §1 (WordPress core — posts & pages) — automated coverage for what
 * was verified by hand this session via ZQTEST01-ZQTEST20 sentinels.
 * Run: wp eval-file tests/db/section1_posts_pages_it.php
 *
 * Scanning LOGIC only — StructuredSourceScanner is called directly, same as
 * reconcile_it.php, not through the real WordPress hooks (that's what
 * section3_media_it.php is for, since the bug found there lived in the hook
 * wiring itself, not the scanning logic).
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Database\Repository\WpDbBoardQueries;
use LingoWP\Extraction\Structured\StructuredSourceScanner;
use LingoWP\Shared\Parsing\HtmlUnitExtractor;
use LingoWP\Shared\Parsing\TranslationUnitCodec;
use LingoWP\Shared\Source\Type\PostSource;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Shared\Text\TranslationKey;
use LingoWP\Database\TranslationMemorySchema;

global $wpdb;

test_guard();
test_begin();

$board    = new WpDbBoardQueries($wpdb);
$repo     = new WpDbSourceRepository($wpdb, $board);
$scanner  = new StructuredSourceScanner($repo, new HtmlUnitExtractor(new TranslationUnitCodec()));
$parents  = TranslationMemorySchema::parentTables();
$PP       = $parents['post'];
$discovered = TranslationStatus::SOURCE_DISCOVERED;

$now = current_time('mysql');

/** Raw wp_posts fixture, bypassing wp_insert_post() — no hooks fire. */
$makePost = static function (array $overrides) use ($wpdb, $now): int {
    return test_insert($wpdb->posts, array_merge([
        'post_author'       => 1,
        'post_date'         => $now,
        'post_date_gmt'     => $now,
        'post_content'      => '',
        'post_title'        => 'LINGOWP_TEST fixture',
        'post_excerpt'      => '',
        'post_status'       => 'publish',
        'comment_status'    => 'closed',
        'ping_status'       => 'closed',
        'post_name'         => 'lingowp-test-fixture-' . wp_generate_password(8, false),
        'post_password'     => '',
        'post_modified'     => $now,
        'post_modified_gmt' => $now,
        'post_type'         => 'post',
    ], $overrides));
};

$titleRows = static function (int $postId) use ($wpdb, $PP, $discovered): int {
    return test_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND field = 'post_title' AND status = %d", $postId, $discovered);
};

// ── 1. Non-visitor-visible statuses are never scanned ────────────────────────

test_section('non-visitor-visible statuses (checklist §1, visitor-visible-only rule):');

foreach (['draft', 'future', 'private', 'trash', 'auto-draft'] as $status) {
    $id = $makePost(['post_status' => $status, 'post_title' => 'LINGOWP_TEST ' . $status]);
    $scanner->refreshPost($id);
    test_ok("  $status post: nothing discovered", $titleRows($id) === 0, "post_id=$id");
}

// ── 2. Password-protected IS scanned — visitor-visible-only, not password-only ─

test_section('password-protected post (checklist §1, visible with the password):');

$pwId = $makePost(['post_status' => 'publish', 'post_password' => 'secret', 'post_title' => 'LINGOWP_TEST protected']);
$scanner->refreshPost($pwId);
test_ok('password-protected post IS discovered', $titleRows($pwId) === 1, "post_id=$pwId");

// ── 3. Revision post_type is never scanned (distinct from post_status) ───────

test_section('revision post type:');

$revId = $makePost(['post_type' => 'revision', 'post_title' => 'LINGOWP_TEST revision']);
$scanner->refreshPost($revId);
test_ok('revision post type: nothing discovered', $titleRows($revId) === 0, "post_id=$revId");

// ── 4. Trash visibility — the regression test for the trash-prune-gap fix ────

test_section('trash visibility (regression: board hides a trashed post without deleting its data):');

$trashPostId = $makePost(['post_status' => 'publish', 'post_title' => 'LINGOWP_TEST trash target']);
$repo->upsertSources([new PostSource($trashPostId, 'post_title', 'LINGOWP_TEST trash target')]);

$before = $board->listPostCollections('fr', 'LINGOWP_TEST trash target');
test_ok('published post appears on the board', count($before) === 1, 'got ' . json_encode($before));

$wpdb->update($wpdb->posts, ['post_status' => 'trash'], ['ID' => $trashPostId]);
$duringTrash = $board->listPostCollections('fr', 'LINGOWP_TEST trash target');
test_ok('trashed post disappears from the board', $duringTrash === [], 'got ' . json_encode($duringTrash));
test_ok('its wp_lingowp_post row is untouched while trashed',
    test_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND status = %d", $trashPostId, $discovered) === 1);

$wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $trashPostId]);
$afterRepublish = $board->listPostCollections('fr', 'LINGOWP_TEST trash target');
test_ok('republished post reappears on the board with its data intact',
    count($afterRepublish) === 1 && $afterRepublish[0]['total'] === $before[0]['total'],
    'got ' . json_encode($afterRepublish));

// ── 5. Custom post type: public vs. non-public ───────────────────────────────

test_section('custom post type visibility (checklist §1):');

register_post_type('lingowp_test_pub', ['public' => true, 'label' => 'LINGOWP_TEST Public CPT']);
register_post_type('lingowp_test_priv', ['public' => false, 'label' => 'LINGOWP_TEST Private CPT']);

$pubId  = $makePost(['post_type' => 'lingowp_test_pub', 'post_title' => 'LINGOWP_TEST public cpt item']);
$privId = $makePost(['post_type' => 'lingowp_test_priv', 'post_title' => 'LINGOWP_TEST private cpt item']);
$scanner->refreshPost($pubId);
$scanner->refreshPost($privId);

test_ok('public => true CPT is discovered', $titleRows($pubId) === 1, "post_id=$pubId");
test_ok('public => false CPT is NOT discovered', $titleRows($privId) === 0, "post_id=$privId");

// ── 6. Hierarchical CPT: parent + child scanned independently ────────────────

test_section('hierarchical CPT parent/child:');

$parentId = $makePost(['post_type' => 'lingowp_test_pub', 'post_title' => 'LINGOWP_TEST hierarchy parent']);
$childId  = $makePost(['post_type' => 'lingowp_test_pub', 'post_title' => 'LINGOWP_TEST hierarchy child', 'post_parent' => $parentId]);
$scanner->refreshPost($parentId);
$scanner->refreshPost($childId);

test_ok('parent discovered', $titleRows($parentId) === 1, "post_id=$parentId");
test_ok('child discovered independently', $titleRows($childId) === 1, "post_id=$childId");

// ── 7. Empty title / image-only content produces nothing ─────────────────────

test_section('empty title, no extractable content:');

$emptyId = $makePost(['post_title' => '', 'post_content' => '<img src="a.jpg" alt=""/>']);
$scanner->refreshPost($emptyId);
test_ok('empty title produces no post_title row', $titleRows($emptyId) === 0, "post_id=$emptyId");
test_ok('image-only content produces no post_content row',
    test_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND field = 'post_content' AND status = %d", $emptyId, $discovered) === 0);

// ── 8. Content normalization (checklist §4 finding): discovery must match what
// WordPress actually shows a visitor, not the raw stored value ──────────────

test_section('content normalization (regression: plain-text content + texturize):');

// Bug A: plain text (classic editor / imports / menu item descriptions) has no
// HTML wrapper at all — WordPress only wraps it into <p> tags at display time.
$plainId = $makePost([
    'post_title'   => 'LINGOWP_TEST plain text post',
    'post_content' => "LINGOWP_TEST plain paragraph one.\n\nLINGOWP_TEST plain paragraph two.",
]);
$scanner->refreshPost($plainId);
$plainRows = $wpdb->get_col($wpdb->prepare(
    "SELECT original_text FROM {$PP} WHERE post_id = %d AND field = 'post_content' AND status = %d ORDER BY id",
    $plainId, $discovered
));
test_ok('plain-text content (no HTML) is discovered as two separate paragraphs',
    $plainRows === ['LINGOWP_TEST plain paragraph one.', 'LINGOWP_TEST plain paragraph two.'],
    'got ' . json_encode($plainRows));

// Bug B: WordPress texturizes a title (straight quote -> curly, "--" -> em dash)
// before a visitor ever sees it (the_title runs core's own wptexturize before
// this plugin's render filter) — discovery must store the SAME texturized text,
// or a translation would show done in the board but never render on the page.
$puncTitle = 'LINGOWP_TEST "quoted" -- title';
$puncId = $makePost(['post_title' => $puncTitle]);
$scanner->refreshPost($puncId);
$storedTitleHash = test_var("SELECT LOWER(HEX(original_hash)) FROM {$PP} WHERE post_id = %d AND field = 'post_title'", $puncId);
$renderTitleHash = strtolower(bin2hex(TranslationKey::currentHash(wptexturize($puncTitle))));
test_ok('a title with texturize-affected punctuation hashes the same as WordPress\'s own the_title output',
    $storedTitleHash === $renderTitleHash, "stored=$storedTitleHash render=$renderTitleHash");

// Gutenberg content must NOT be re-wrapped by the has_blocks() guard (regression
// guard: an earlier unconditional wpautop() would corrupt block comments into
// garbage empty-paragraph units).
$gutenbergId = $makePost([
    'post_content' => '<!-- wp:paragraph --><p>LINGOWP_TEST gutenberg paragraph.</p><!-- /wp:paragraph -->',
]);
$scanner->refreshPost($gutenbergId);
$gutenbergRows = $wpdb->get_col($wpdb->prepare(
    "SELECT original_text FROM {$PP} WHERE post_id = %d AND field = 'post_content' AND status = %d",
    $gutenbergId, $discovered
));
test_ok('real block content produces exactly one clean unit, not corrupted by wpautop',
    $gutenbergRows === ['LINGOWP_TEST gutenberg paragraph.'], 'got ' . json_encode($gutenbergRows));

test_done();

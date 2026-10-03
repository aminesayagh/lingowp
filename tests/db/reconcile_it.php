<?php

/**
 * Core reconciliation rules — reconcileScope() and carryForward().
 * Run: wp eval-file tests/db/reconcile_it.php
 * Mirrors §"Phase 2 — reconcile_it" of plan-database-pipeline-verification.md.
 *
 * Also covers finding (A): carry-forward across a multi-piece post_content
 * edit. See EXPECT_CARRY_FORWARD below.
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Database\Repository\WpDbBoardQueries;
use LingoWP\Shared\Source\Type\PostMetaSource;
use LingoWP\Shared\Source\Type\PostSource;
use LingoWP\Shared\Source\Type\SplitPostSource;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Shared\Text\TranslationKey;
use LingoWP\Database\TranslationMemorySchema;

/**
 * FINDING (A) — FIXED, so this is now TRUE.
 *
 * Editing one paragraph of a multi-piece post used to drop its translation:
 * location is (post_id, field), every split post_content piece shares
 * (post_id, 'post_content'), so the old `count($targets) === 1` guard saw N
 * candidates and refused to carry forward.
 *
 * reconcileScope() now pairs a location's single removed hash with its single
 * NEWLY INSERTED one, so an unambiguous single-paragraph edit carries its
 * translation across as needs_review.
 *
 * Still not carried (deliberate): two or more pieces edited in one save, where
 * nothing says which old text became which new one.
 */
const EXPECT_CARRY_FORWARD = true;

global $wpdb;

test_guard();
test_begin();

$repo    = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
$parents = TranslationMemorySchema::parentTables();
$PP      = $parents['post'];
$PC      = $PP . '_translation';
$MP      = $parents['meta'];
$MC      = $MP . '_translation';

$now = current_time('mysql');

$postId = test_insert($wpdb->posts, [
    'post_author'       => 1,
    'post_date'         => $now,
    'post_date_gmt'     => $now,
    'post_content'      => '',
    'post_title'        => 'LINGOWP_TEST Fixture Post',
    'post_excerpt'      => '',
    'post_status'       => 'publish',
    'comment_status'    => 'closed',
    'ping_status'       => 'closed',
    'post_name'         => 'lingowp-test-fixture-post',
    'post_modified'     => $now,
    'post_modified_gmt' => $now,
    'post_type'         => 'post',
]);
test_ok('fixture post created', $postId > 0, "post_id=$postId");

$hex      = static fn(string $t): string => strtolower(bin2hex(TranslationKey::currentHash($t)));
$discovered = TranslationStatus::SOURCE_DISCOVERED;

$postRows = static fn(): int =>
    test_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND status = %d", $postId, $discovered);

// ── 1. The single chokepoint ─────────────────────────────────────────────────

test_section('insertParents chokepoint:');

$n = $repo->upsertSources([new PostSource($postId, 'post_title', 'https://x.com/a')]);
test_ok('whole-string url never reaches a parent table', $n === 0 && $postRows() === 0, "inserted=$n");

// ── 2. upsertSources is INSERT IGNORE ────────────────────────────────────────

test_section('upsertSources = INSERT IGNORE:');

$repo->upsertSources([new PostSource($postId, 'post_title', 'Hello World')]);
$firstId = (int) test_var("SELECT id FROM {$PP} WHERE post_id = %d AND field = 'post_title'", $postId);
$firstAt = (string) test_var("SELECT created_at FROM {$PP} WHERE id = %d", $firstId);

$again = $repo->upsertSources([new PostSource($postId, 'post_title', 'Hello World')]);
test_ok('second upsert inserts nothing', $again === 0, "got $again");
test_ok('id unchanged', (int) test_var("SELECT id FROM {$PP} WHERE post_id = %d AND field = 'post_title'", $postId) === $firstId);
test_ok('created_at unchanged', (string) test_var("SELECT created_at FROM {$PP} WHERE id = %d", $firstId) === $firstAt);
test_ok('parent status is 0', (int) test_var("SELECT status FROM {$PP} WHERE id = %d", $firstId) === $discovered);

// ── 3. Normalization is end-to-end, not just in PHP ──────────────────────────

test_section('normalization end-to-end:');

$repo->reconcilePost($postId, [new PostSource($postId, 'post_title', '  HELLO   world ')]);
test_ok('normalized-equal text is not treated as an edit',
    (int) test_var("SELECT id FROM {$PP} WHERE post_id = %d AND field = 'post_title'", $postId) === $firstId);
test_ok('still exactly one title row',
    test_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND field = 'post_title' AND status = %d", $postId, $discovered) === 1);

// ── 4. Carry-forward for a single position ───────────────────────────────────

test_section('carry-forward (single position):');

$repo->saveTranslation(SourceRef::make('post', $firstId), 'fr', 'Bonjour le monde', TranslationStatus::MACHINE, 'prov-fr');
$repo->saveTranslation(SourceRef::make('post', $firstId), 'es', 'Hola mundo',       TranslationStatus::REVIEWED, null);

$oldHash   = $hex('Hello World');
$oldFrMade = (string) test_var("SELECT created_at FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = 'fr'", $oldHash);

$repo->reconcilePost($postId, [new PostSource($postId, 'post_title', 'Hello there')]);
$newHash = $hex('Hello there');

test_ok('old parent deleted',
    test_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $oldHash) === 0);
test_ok('new parent exists',
    test_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $newHash) === 1);

foreach (['fr' => 'Bonjour le monde', 'es' => 'Hola mundo'] as $lang => $text) {
    $r = $wpdb->get_row($wpdb->prepare(
        "SELECT translated_text, status, provider, created_at, updated_at FROM {$PC}
         WHERE original_hash = UNHEX(%s) AND lang_code = %s", $newHash, $lang), ARRAY_A);
    test_ok("  $lang carried forward", $r !== null);
    test_ok("  $lang text preserved", ($r['translated_text'] ?? null) === $text);
    test_ok("  $lang status is NEEDS_REVIEW", (int) ($r['status'] ?? 0) === TranslationStatus::NEEDS_REVIEW);
}

$frNew = $wpdb->get_row($wpdb->prepare(
    "SELECT provider, created_at FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = 'fr'", $newHash), ARRAY_A);
test_ok('  provider preserved', ($frNew['provider'] ?? null) === 'prov-fr');
test_ok('  created_at preserved', ($frNew['created_at'] ?? null) === $oldFrMade);
test_ok('old child survives for the sweep',
    test_count("SELECT COUNT(*) FROM {$PC} WHERE original_hash = UNHEX(%s)", $oldHash) === 2);

// ── 5. Carry-forward never clobbers an existing target translation ───────────

test_section('no-clobber:');

$newId = (int) test_var("SELECT id FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $newHash);
$repo->saveTranslation(SourceRef::make('post', $newId), 'fr', 'Deja traduit', TranslationStatus::REVIEWED, null);
$repo->reconcilePost($postId, [new PostSource($postId, 'post_title', 'Hello again')]);
$thirdHash = $hex('Hello again');

$fr = $wpdb->get_row($wpdb->prepare(
    "SELECT translated_text, status FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = 'fr'", $thirdHash), ARRAY_A);
test_ok('carry-forward seeded fr onto the new hash', $fr !== null);

$thirdId = (int) test_var("SELECT id FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $thirdHash);
$repo->saveTranslation(SourceRef::make('post', $thirdId), 'de', 'Hallo', TranslationStatus::REVIEWED, null);
$repo->reconcilePost($postId, [new PostSource($postId, 'post_title', 'Hello fourth')]);
$fourthHash = $hex('Hello fourth');
$de = $wpdb->get_row($wpdb->prepare(
    "SELECT translated_text, status FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = 'de'", $fourthHash), ARRAY_A);
test_ok('an existing target translation is never overwritten',
    ($de['translated_text'] ?? null) === 'Hallo');

// ── 6-7. Empty records prune only status 0; stale always deleted ─────────────

test_section('empty reconcile + ignored survivors:');

$fourthId = (int) test_var("SELECT id FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $fourthHash);
$repo->upsertSources([new PostSource($postId, 'post_excerpt', 'LINGOWP_TEST excerpt text')]);
$excerptId = (int) test_var("SELECT id FROM {$PP} WHERE post_id = %d AND field = 'post_excerpt'", $postId);
$repo->ignoreSource(SourceRef::make('post', $excerptId));

test_ok('excerpt parent is now IGNORED',
    (int) test_var("SELECT status FROM {$PP} WHERE id = %d", $excerptId) === TranslationStatus::SOURCE_IGNORED);

$repo->reconcilePost($postId, []);

test_ok('empty reconcile deletes discovered parents', $postRows() === 0);
test_ok('empty reconcile SPARES the ignored parent',
    test_count("SELECT COUNT(*) FROM {$PP} WHERE id = %d", $excerptId) === 1);

// ── 8. FINDING (A): multi-piece post_content ─────────────────────────────────

test_section('finding (A) — multi-piece post_content:');

$pieceA = 'LINGOWP_TEST paragraph alpha';
$pieceB = 'LINGOWP_TEST paragraph beta';
$repo->reconcilePost($postId, [
    new SplitPostSource($postId, 'post_content', $pieceA),
    new SplitPostSource($postId, 'post_content', $pieceB),
]);

test_ok('two content pieces discovered',
    test_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND field = 'post_content' AND status = %d", $postId, $discovered) === 2);

$bId = (int) test_var(
    "SELECT id FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $hex($pieceB));
$repo->saveTranslation(SourceRef::make('post', $bId), 'fr', 'paragraphe beta', TranslationStatus::MACHINE, null);

$pieceBEdited = 'LINGOWP_TEST paragraph beta edited';
$repo->reconcilePost($postId, [
    new SplitPostSource($postId, 'post_content', $pieceA),
    new SplitPostSource($postId, 'post_content', $pieceBEdited),
]);

$carriedToEdited = test_count(
    "SELECT COUNT(*) FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = 'fr'", $hex($pieceBEdited));

test_ok('stale piece parent is deleted regardless',
    test_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $hex($pieceB)) === 0);

if (EXPECT_CARRY_FORWARD) {
    test_ok('translation carried to the edited piece', $carriedToEdited === 1,
        "got $carriedToEdited — carry-forward still does not fire for multi-piece content");
} else {
    test_ok('PINNED: no carry-forward for multi-piece content (finding A)',
        $carriedToEdited === 0,
        "got $carriedToEdited — carry-forward now FIRES; flip EXPECT_CARRY_FORWARD to true");
}

test_ok('the old translation is orphaned, awaiting the sweep',
    test_count("SELECT COUNT(*) FROM {$PC} WHERE original_hash = UNHEX(%s)", $hex($pieceB)) === 1);

// ── 9. parent_id path cascades instead of leaking ────────────────────────────

test_section('parent_id path (meta):');

$metaKey = '_yoast_wpseo_title';
$repo->reconcilePostMetaKey($postId, $metaKey, [new PostMetaSource($postId, $metaKey, null, 'LINGOWP_TEST meta original')]);
$metaId = (int) test_var("SELECT id FROM {$MP} WHERE post_id = %d", $postId);
test_ok('meta parent created', $metaId > 0);

$repo->saveTranslation(SourceRef::make('meta', $metaId), 'fr', 'meta traduit', TranslationStatus::MACHINE, null);
test_ok('meta translation stored',
    test_count("SELECT COUNT(*) FROM {$MC} WHERE parent_id = %d", $metaId) === 1);

$repo->reconcilePostMetaKey($postId, $metaKey, [new PostMetaSource($postId, $metaKey, null, 'LINGOWP_TEST meta edited')]);
$newMetaId = (int) test_var("SELECT id FROM {$MP} WHERE post_id = %d AND status = %d", $postId, $discovered);

test_ok('meta carried forward to the new parent',
    test_count("SELECT COUNT(*) FROM {$MC} WHERE parent_id = %d AND lang_code = 'fr'", $newMetaId) === 1);
test_ok('  status is NEEDS_REVIEW',
    (int) test_var("SELECT status FROM {$MC} WHERE parent_id = %d AND lang_code = 'fr'", $newMetaId) === TranslationStatus::NEEDS_REVIEW);
test_ok('stale meta child CASCADED away (no sweep needed)',
    test_count("SELECT COUNT(*) FROM {$MC} WHERE parent_id = %d", $metaId) === 0);

// ── 10. Render lookups ignore child status ───────────────────────────────────

test_section('render is blind to child status:');

$map = $repo->fetchHashTranslations('post', [TranslationKey::currentHash('Hello fourth')], 'de');
test_ok('a NEEDS_REVIEW/REVIEWED child renders identically',
    ($map[$fourthHash] ?? null) === 'Hallo', 'map=' . json_encode($map));

$repo->ignoreSource(SourceRef::make('meta', $newMetaId));
$lookups = [['post_id' => $postId, 'meta_key_hash' => hash('sha256', $metaKey, true), 'context_hash' => hash('sha256', '', true)]];
$metaMap = $repo->fetchParentTranslations('meta', $lookups, 'fr');
test_ok('an IGNORED parent returns nothing (parent_id path filters status = 0)',
    $metaMap === [], 'got ' . json_encode($metaMap));

test_done();

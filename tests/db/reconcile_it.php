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

use TransLangs\Database\WpDbSourceRepository;
use TransLangs\Database\WpDbBoardQueries;
use TransLangs\Shared\Source\PostMetaSource;
use TransLangs\Shared\Source\PostSource;
use TransLangs\Shared\Source\SplitPostSource;
use TransLangs\Shared\Source\SourceRef;
use TransLangs\Shared\Source\TranslationStatus;
use TransLangs\Shared\Text\TL_Key;
use TransLangs\Database\TranslationMemorySchema;

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

tl_guard();
tl_begin();

$repo    = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
$parents = TranslationMemorySchema::parentTables();
$PP      = $parents['tl_post'];
$PC      = $PP . '_translation';
$MP      = $parents['tl_meta'];
$MC      = $MP . '_translation';

$now = current_time('mysql');

$postId = tl_insert($wpdb->posts, [
    'post_author'       => 1,
    'post_date'         => $now,
    'post_date_gmt'     => $now,
    'post_content'      => '',
    'post_title'        => 'TLTEST Fixture Post',
    'post_excerpt'      => '',
    'post_status'       => 'publish',
    'comment_status'    => 'closed',
    'ping_status'       => 'closed',
    'post_name'         => 'tltest-fixture-post',
    'post_modified'     => $now,
    'post_modified_gmt' => $now,
    'post_type'         => 'post',
]);
tl_ok('fixture post created', $postId > 0, "post_id=$postId");

$hex      = static fn(string $t): string => strtolower(bin2hex(TL_Key::currentHash($t)));
$discovered = TranslationStatus::SOURCE_DISCOVERED;

$postRows = static fn(): int =>
    tl_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND status = %d", $postId, $discovered);

// ── 1. The single chokepoint ─────────────────────────────────────────────────

tl_section('insertParents chokepoint:');

$n = $repo->upsertSources([new PostSource($postId, 'post_title', 'https://x.com/a')]);
tl_ok('whole-string url never reaches a parent table', $n === 0 && $postRows() === 0, "inserted=$n");

// ── 2. upsertSources is INSERT IGNORE ────────────────────────────────────────

tl_section('upsertSources = INSERT IGNORE:');

$repo->upsertSources([new PostSource($postId, 'post_title', 'Hello World')]);
$firstId = (int) tl_var("SELECT id FROM {$PP} WHERE post_id = %d AND field = 'post_title'", $postId);
$firstAt = (string) tl_var("SELECT created_at FROM {$PP} WHERE id = %d", $firstId);

$again = $repo->upsertSources([new PostSource($postId, 'post_title', 'Hello World')]);
tl_ok('second upsert inserts nothing', $again === 0, "got $again");
tl_ok('id unchanged', (int) tl_var("SELECT id FROM {$PP} WHERE post_id = %d AND field = 'post_title'", $postId) === $firstId);
tl_ok('created_at unchanged', (string) tl_var("SELECT created_at FROM {$PP} WHERE id = %d", $firstId) === $firstAt);
tl_ok('parent status is 0', (int) tl_var("SELECT status FROM {$PP} WHERE id = %d", $firstId) === $discovered);

// ── 3. Normalization is end-to-end, not just in PHP ──────────────────────────

tl_section('normalization end-to-end:');

$repo->reconcilePost($postId, [new PostSource($postId, 'post_title', '  HELLO   world ')]);
tl_ok('normalized-equal text is not treated as an edit',
    (int) tl_var("SELECT id FROM {$PP} WHERE post_id = %d AND field = 'post_title'", $postId) === $firstId);
tl_ok('still exactly one title row',
    tl_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND field = 'post_title' AND status = %d", $postId, $discovered) === 1);

// ── 4. Carry-forward for a single position ───────────────────────────────────

tl_section('carry-forward (single position):');

$repo->saveTranslation(SourceRef::make('post', $firstId), 'fr', 'Bonjour le monde', TranslationStatus::MACHINE, 'prov-fr');
$repo->saveTranslation(SourceRef::make('post', $firstId), 'es', 'Hola mundo',       TranslationStatus::REVIEWED, null);

$oldHash   = $hex('Hello World');
$oldFrMade = (string) tl_var("SELECT created_at FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = 'fr'", $oldHash);

$repo->reconcilePost($postId, [new PostSource($postId, 'post_title', 'Hello there')]);
$newHash = $hex('Hello there');

tl_ok('old parent deleted',
    tl_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $oldHash) === 0);
tl_ok('new parent exists',
    tl_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $newHash) === 1);

foreach (['fr' => 'Bonjour le monde', 'es' => 'Hola mundo'] as $lang => $text) {
    $r = $wpdb->get_row($wpdb->prepare(
        "SELECT translated_text, status, provider, created_at, updated_at FROM {$PC}
         WHERE original_hash = UNHEX(%s) AND lang_code = %s", $newHash, $lang), ARRAY_A);
    tl_ok("  $lang carried forward", $r !== null);
    tl_ok("  $lang text preserved", ($r['translated_text'] ?? null) === $text);
    tl_ok("  $lang status is NEEDS_REVIEW", (int) ($r['status'] ?? 0) === TranslationStatus::NEEDS_REVIEW);
}

$frNew = $wpdb->get_row($wpdb->prepare(
    "SELECT provider, created_at FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = 'fr'", $newHash), ARRAY_A);
tl_ok('  provider preserved', ($frNew['provider'] ?? null) === 'prov-fr');
tl_ok('  created_at preserved', ($frNew['created_at'] ?? null) === $oldFrMade);
tl_ok('old child survives for the sweep',
    tl_count("SELECT COUNT(*) FROM {$PC} WHERE original_hash = UNHEX(%s)", $oldHash) === 2);

// ── 5. Carry-forward never clobbers an existing target translation ───────────

tl_section('no-clobber:');

$newId = (int) tl_var("SELECT id FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $newHash);
$repo->saveTranslation(SourceRef::make('post', $newId), 'fr', 'Deja traduit', TranslationStatus::REVIEWED, null);
$repo->reconcilePost($postId, [new PostSource($postId, 'post_title', 'Hello again')]);
$thirdHash = $hex('Hello again');

$fr = $wpdb->get_row($wpdb->prepare(
    "SELECT translated_text, status FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = 'fr'", $thirdHash), ARRAY_A);
tl_ok('carry-forward seeded fr onto the new hash', $fr !== null);

$thirdId = (int) tl_var("SELECT id FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $thirdHash);
$repo->saveTranslation(SourceRef::make('post', $thirdId), 'de', 'Hallo', TranslationStatus::REVIEWED, null);
$repo->reconcilePost($postId, [new PostSource($postId, 'post_title', 'Hello fourth')]);
$fourthHash = $hex('Hello fourth');
$de = $wpdb->get_row($wpdb->prepare(
    "SELECT translated_text, status FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = 'de'", $fourthHash), ARRAY_A);
tl_ok('an existing target translation is never overwritten',
    ($de['translated_text'] ?? null) === 'Hallo');

// ── 6-7. Empty records prune only status 0; stale always deleted ─────────────

tl_section('empty reconcile + ignored survivors:');

$fourthId = (int) tl_var("SELECT id FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $fourthHash);
$repo->upsertSources([new PostSource($postId, 'post_excerpt', 'TLTEST excerpt text')]);
$excerptId = (int) tl_var("SELECT id FROM {$PP} WHERE post_id = %d AND field = 'post_excerpt'", $postId);
$repo->ignoreSource(SourceRef::make('post', $excerptId));

tl_ok('excerpt parent is now IGNORED',
    (int) tl_var("SELECT status FROM {$PP} WHERE id = %d", $excerptId) === TranslationStatus::SOURCE_IGNORED);

$repo->reconcilePost($postId, []);

tl_ok('empty reconcile deletes discovered parents', $postRows() === 0);
tl_ok('empty reconcile SPARES the ignored parent',
    tl_count("SELECT COUNT(*) FROM {$PP} WHERE id = %d", $excerptId) === 1);

// ── 8. FINDING (A): multi-piece post_content ─────────────────────────────────

tl_section('finding (A) — multi-piece post_content:');

$pieceA = 'TLTEST paragraph alpha';
$pieceB = 'TLTEST paragraph beta';
$repo->reconcilePost($postId, [
    new SplitPostSource($postId, 'post_content', $pieceA),
    new SplitPostSource($postId, 'post_content', $pieceB),
]);

tl_ok('two content pieces discovered',
    tl_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND field = 'post_content' AND status = %d", $postId, $discovered) === 2);

$bId = (int) tl_var(
    "SELECT id FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $hex($pieceB));
$repo->saveTranslation(SourceRef::make('post', $bId), 'fr', 'paragraphe beta', TranslationStatus::MACHINE, null);

$pieceBEdited = 'TLTEST paragraph beta edited';
$repo->reconcilePost($postId, [
    new SplitPostSource($postId, 'post_content', $pieceA),
    new SplitPostSource($postId, 'post_content', $pieceBEdited),
]);

$carriedToEdited = tl_count(
    "SELECT COUNT(*) FROM {$PC} WHERE original_hash = UNHEX(%s) AND lang_code = 'fr'", $hex($pieceBEdited));

tl_ok('stale piece parent is deleted regardless',
    tl_count("SELECT COUNT(*) FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $hex($pieceB)) === 0);

if (EXPECT_CARRY_FORWARD) {
    tl_ok('translation carried to the edited piece', $carriedToEdited === 1,
        "got $carriedToEdited — carry-forward still does not fire for multi-piece content");
} else {
    tl_ok('PINNED: no carry-forward for multi-piece content (finding A)',
        $carriedToEdited === 0,
        "got $carriedToEdited — carry-forward now FIRES; flip EXPECT_CARRY_FORWARD to true");
}

tl_ok('the old translation is orphaned, awaiting the sweep',
    tl_count("SELECT COUNT(*) FROM {$PC} WHERE original_hash = UNHEX(%s)", $hex($pieceB)) === 1);

// ── 9. parent_id path cascades instead of leaking ────────────────────────────

tl_section('parent_id path (tl_meta):');

$metaKey = '_yoast_wpseo_title';
$repo->reconcilePostMetaKey($postId, $metaKey, [new PostMetaSource($postId, $metaKey, null, 'TLTEST meta original')]);
$metaId = (int) tl_var("SELECT id FROM {$MP} WHERE post_id = %d", $postId);
tl_ok('meta parent created', $metaId > 0);

$repo->saveTranslation(SourceRef::make('meta', $metaId), 'fr', 'meta traduit', TranslationStatus::MACHINE, null);
tl_ok('meta translation stored',
    tl_count("SELECT COUNT(*) FROM {$MC} WHERE parent_id = %d", $metaId) === 1);

$repo->reconcilePostMetaKey($postId, $metaKey, [new PostMetaSource($postId, $metaKey, null, 'TLTEST meta edited')]);
$newMetaId = (int) tl_var("SELECT id FROM {$MP} WHERE post_id = %d AND status = %d", $postId, $discovered);

tl_ok('meta carried forward to the new parent',
    tl_count("SELECT COUNT(*) FROM {$MC} WHERE parent_id = %d AND lang_code = 'fr'", $newMetaId) === 1);
tl_ok('  status is NEEDS_REVIEW',
    (int) tl_var("SELECT status FROM {$MC} WHERE parent_id = %d AND lang_code = 'fr'", $newMetaId) === TranslationStatus::NEEDS_REVIEW);
tl_ok('stale meta child CASCADED away (no sweep needed)',
    tl_count("SELECT COUNT(*) FROM {$MC} WHERE parent_id = %d", $metaId) === 0);

// ── 10. Render lookups ignore child status ───────────────────────────────────

tl_section('render is blind to child status:');

$map = $repo->fetchHashTranslations('tl_post', [TL_Key::currentHash('Hello fourth')], 'de');
tl_ok('a NEEDS_REVIEW/REVIEWED child renders identically',
    ($map[$fourthHash] ?? null) === 'Hallo', 'map=' . json_encode($map));

$repo->ignoreSource(SourceRef::make('meta', $newMetaId));
$lookups = [['post_id' => $postId, 'meta_key_hash' => hash('sha256', $metaKey, true), 'context_hash' => hash('sha256', '', true)]];
$metaMap = $repo->fetchParentTranslations('tl_meta', $lookups, 'fr');
tl_ok('an IGNORED parent returns nothing (parent_id path filters status = 0)',
    $metaMap === [], 'got ' . json_encode($metaMap));

tl_done();

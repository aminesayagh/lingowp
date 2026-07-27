<?php

/**
 * OrphanTranslationSweep — brand-new code, never run before this file.
 * Run: wp eval-file tests/db/orphan_sweep_it.php
 * Mirrors §"Phase 2 — orphan_sweep_it" of plan-database-pipeline-verification.md.
 *
 * The sweep issues DELETE against four tables. Everything here runs inside the
 * harness transaction and is rolled back, but read the isolation contract in
 * _harness.php before changing anything.
 */

require __DIR__ . '/_harness.php';

use TransLangs\Core\Source\Value\TranslationStatus;
use TransLangs\Database\OrphanTranslationSweep;
use TransLangs\Database\TranslationMemorySchema;

global $wpdb;

tl_guard();
tl_begin();

$parents = TranslationMemorySchema::parentTables();
$sweep   = new OrphanTranslationSweep($wpdb);
$LANG    = 'fr';

/** Insert a hash-link child row with an explicit age in SITE-LOCAL time. */
function tl_child(string $table, string $hashHex, string $lang, int $ageSeconds): void
{
    global $wpdb;
    $stamp = gmdate('Y-m-d H:i:s', current_time('timestamp') - $ageSeconds);
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table} (original_hash, lang_code, translated_text, status, provider, created_at, updated_at)
         VALUES (UNHEX(%s), %s, %s, %d, NULL, %s, %s)",
        $hashHex, $lang, 'TLTEST translated', TranslationStatus::MACHINE, $stamp, $stamp
    ));
}

$childExists = static function (string $table, string $hashHex) use ($wpdb, $LANG): bool {
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $hashHex, $LANG)) > 0;
};

$randHash = static fn(): string => strtolower(bin2hex(random_bytes(32)));

$postChild = $parents['tl_post'] . '_translation';
$postPar   = $parents['tl_post'];

// ── 1-2. The grace period ────────────────────────────────────────────────────

tl_section('one-hour grace period:');

$fresh = $randHash();
$aged  = $randHash();
tl_child($postChild, $fresh, $LANG, 0);
tl_child($postChild, $aged,  $LANG, 7200);

$sweep->run();

tl_ok('a fresh orphan SURVIVES (grace period)', $childExists($postChild, $fresh));
tl_ok('a 2h-old orphan is deleted',            !$childExists($postChild, $aged));

// ── 3. A child with a living parent is never touched ─────────────────────────

tl_section('children with a surviving parent:');

$kept   = $randHash();
$now    = current_time('mysql');
$postId = tl_insert($wpdb->posts, [
    'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => $now,
    'post_content' => '', 'post_title' => 'TLTEST Sweep Post', 'post_excerpt' => '',
    'post_status' => 'publish', 'comment_status' => 'closed', 'ping_status' => 'closed',
    'post_name' => 'tltest-sweep-post', 'post_modified' => $now, 'post_modified_gmt' => $now,
    'post_type' => 'post',
]);

$wpdb->query($wpdb->prepare(
    "INSERT INTO {$postPar} (post_id, field, original_hash, original_text, status, created_at, updated_at)
     VALUES (%d, 'post_title', UNHEX(%s), %s, %d, %s, %s)",
    $postId, $kept, 'TLTEST kept', TranslationStatus::SOURCE_DISCOVERED, $now, $now
));
tl_child($postChild, $kept, $LANG, 7200);

$sweep->run();
tl_ok('aged child with a live parent survives', $childExists($postChild, $kept));

// ── 4. Many parents, one child — the destructive-cascade scenario ────────────

tl_section('many parents share one child:');

$shared  = $randHash();
$postId2 = tl_insert($wpdb->posts, [
    'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => $now,
    'post_content' => '', 'post_title' => 'TLTEST Sweep Post 2', 'post_excerpt' => '',
    'post_status' => 'publish', 'comment_status' => 'closed', 'ping_status' => 'closed',
    'post_name' => 'tltest-sweep-post-2', 'post_modified' => $now, 'post_modified_gmt' => $now,
    'post_type' => 'post',
]);

foreach ([$postId, $postId2] as $pid) {
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$postPar} (post_id, field, original_hash, original_text, status, created_at, updated_at)
         VALUES (%d, 'post_excerpt', UNHEX(%s), %s, %d, %s, %s)",
        $pid, $shared, 'TLTEST shared string', TranslationStatus::SOURCE_DISCOVERED, $now, $now
    ));
}
tl_child($postChild, $shared, $LANG, 7200);

// Delete one of the two owners; the other still renders this translation.
$wpdb->query($wpdb->prepare("DELETE FROM {$postPar} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId, $shared));
$sweep->run();
tl_ok('child survives while ANY parent still holds its hash', $childExists($postChild, $shared));

// Remove the last owner — now it is a true orphan.
$wpdb->query($wpdb->prepare("DELETE FROM {$postPar} WHERE post_id = %d AND original_hash = UNHEX(%s)", $postId2, $shared));
$sweep->run();
tl_ok('child is reclaimed once the LAST parent is gone', !$childExists($postChild, $shared));

// ── 5. parent_id children are out of scope ───────────────────────────────────

tl_section('parent_id children untouched:');

$metaChild = $parents['tl_meta'] . '_translation';
$statChild = $parents['tl_staticText'] . '_translation';

$before = [
    'meta' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$metaChild}"), // nosemgrep: wpdb-interpolated-sql -- integration test, table name from the schema map
    'stat' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$statChild}"), // nosemgrep: wpdb-interpolated-sql -- integration test, table name from the schema map
];
$sweep->run();
tl_ok('tl_meta_translation row count unchanged',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$metaChild}") === $before['meta']); // nosemgrep: wpdb-interpolated-sql -- integration test, table name from the schema map
tl_ok('tl_staticText_translation row count unchanged',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$statChild}") === $before['stat']); // nosemgrep: wpdb-interpolated-sql -- integration test, table name from the schema map

// ── 6. All four hash-link tables are actually swept ──────────────────────────

tl_section('all four hash-link tables swept:');

$planted = [];
foreach (TranslationMemorySchema::HASH_LINK_CHILDREN as $key) {
    $h = $randHash();
    $planted[$key] = $h;
    tl_child($parents[$key] . '_translation', $h, $LANG, 7200);
}

$deleted = $sweep->run();
foreach ($planted as $key => $h) {
    tl_ok("  $key orphan reclaimed", !$childExists($parents[$key] . '_translation', $h));
}
tl_ok('run() returns the number reclaimed', $deleted >= count($planted), "returned $deleted");

// ── 7. Timezone — the cutoff must be built in the same frame as created_at ───
// created_at is written with current_time('mysql') (site-local). A gmdate()
// cutoff fails ASYMMETRICALLY: at UTC+9 rows are spared or killed early, at
// UTC-8 the bug inverts. Testing one offset is a coin flip.

tl_section('timezone (both directions):');

$originalTz = get_option('timezone_string');
$originalOffset = get_option('gmt_offset');

foreach (['Asia/Tokyo', 'America/Los_Angeles', 'UTC'] as $tz) {
    update_option('timezone_string', $tz);

    $freshTz = $randHash();
    $agedTz  = $randHash();
    tl_child($postChild, $freshTz, $LANG, 0);
    tl_child($postChild, $agedTz,  $LANG, 7200);

    $sweep->run();

    tl_ok("  [$tz] fresh orphan survives", $childExists($postChild, $freshTz));
    tl_ok("  [$tz] aged orphan is reclaimed", !$childExists($postChild, $agedTz));
}

update_option('timezone_string', $originalTz);
update_option('gmt_offset', $originalOffset);

// ── 8. The Site Health option finally has a writer ───────────────────────────

tl_section('translangs_age_out_last_run:');

delete_option(OrphanTranslationSweep::OPTION_LAST_RUN);
tl_ok('option cleared for the test', get_option(OrphanTranslationSweep::OPTION_LAST_RUN, '') === '');

$sweep->run();
$stamp = (string) get_option(OrphanTranslationSweep::OPTION_LAST_RUN, '');
tl_ok('sweep writes the timestamp', $stamp !== '', 'got ' . json_encode($stamp));
tl_ok('timestamp is a MySQL datetime',
    (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $stamp), 'got ' . json_encode($stamp));

tl_done();

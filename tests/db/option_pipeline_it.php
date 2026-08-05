<?php

/**
 * Option discovery pipeline — the newest code, run this before reconcile_it.
 * Run: wp eval-file tests/db/option_pipeline_it.php
 * Mirrors §"Phase 2 — option_pipeline_it" of plan-database-pipeline-verification.md.
 *
 * Covers: reconcileOption(), the per-option scan(), the empty-scope prune, the
 * two branding save hooks, and finding (B) — tl_option is hash-link, so two
 * options holding identical text SHARE one translation row.
 *
 * NOTE: on failure here, run reconcile_it.php before diagnosing. This file
 * delegates into reconcileScope()/carryForward(), so a fault in the generic
 * layer surfaces here first.
 */

require __DIR__ . '/_harness.php';

use TransLangs\Database\WpDbSourceRepository;
use TransLangs\Database\WpDbBoardQueries;
use TransLangs\Shared\Source\SourceRef;
use TransLangs\Shared\Source\TranslationStatus;
use TransLangs\Shared\Text\TL_Key;
use TransLangs\Database\TranslationMemorySchema;
use TransLangs\Extraction\OptionSourceScanner;

global $wpdb;

tl_guard();
tl_begin();

$repo    = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
$scanner = new OptionSourceScanner($repo, $wpdb);
$parents = TranslationMemorySchema::parentTables();
$P       = $parents['tl_option'];
$C       = $P . '_translation';
$LANG    = 'fr';

$optionId = (int) tl_var("SELECT option_id FROM {$wpdb->options} WHERE option_name = 'blogname'");
tl_ok('blogname has a wp_options row', $optionId > 0, "option_id=$optionId");

$rowsFor = static fn(int $id): int =>
    tl_count("SELECT COUNT(*) FROM {$P} WHERE option_id = %d AND status = %d", $id, TranslationStatus::SOURCE_DISCOVERED);

// ── 1-2. Discovery and idempotence ───────────────────────────────────────────

tl_section('discovery:');

update_option('blogname', 'TLTEST Original Title');
$scanner->scan('blogname');

tl_ok('scan creates exactly one row', $rowsFor($optionId) === 1, 'got ' . $rowsFor($optionId));

$row = $wpdb->get_row($wpdb->prepare(
    "SELECT id, option_path, option_name, status, LOWER(HEX(original_hash)) AS h, original_text
     FROM {$P} WHERE option_id = %d AND status = %d",
    $optionId,
    TranslationStatus::SOURCE_DISCOVERED
), ARRAY_A);

tl_ok('option_path is empty (scalar option)', $row['option_path'] === '');
tl_ok('option_name recorded', $row['option_name'] === 'blogname');
tl_ok('status is SOURCE_DISCOVERED', (int) $row['status'] === TranslationStatus::SOURCE_DISCOVERED);
tl_ok('original_text is the current title', $row['original_text'] === 'TLTEST Original Title');
tl_ok('original_hash matches TL_Key',
    $row['h'] === strtolower(bin2hex(TL_Key::currentHash('TLTEST Original Title'))));

$firstId = (int) $row['id'];
$scanner->scan('blogname');
tl_ok('scan is idempotent (still one row)', $rowsFor($optionId) === 1);
tl_ok('scan is idempotent (same id)',
    (int) tl_var("SELECT id FROM {$P} WHERE option_id = %d AND status = %d", $optionId, TranslationStatus::SOURCE_DISCOVERED) === $firstId);

// ── 3. The save hook carries the translation forward ─────────────────────────

tl_section('update_option_blogname hook -> carry-forward:');

$repo->saveTranslation(SourceRef::make('option', $firstId), $LANG, 'Titre TLTEST', TranslationStatus::MACHINE, 'test-provider');

$oldHash = $row['h'];
tl_ok('translation stored at the old hash',
    tl_count("SELECT COUNT(*) FROM {$C} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $oldHash, $LANG) === 1);

$oldCreatedAt = (string) tl_var(
    "SELECT created_at FROM {$C} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $oldHash, $LANG);

// This is the wiring under test: the hook in Plugin.php must fire scan('blogname').
update_option('blogname', 'TLTEST New Title');

$newHash = strtolower(bin2hex(TL_Key::currentHash('TLTEST New Title')));

tl_ok('hook fired: new parent exists',
    tl_count("SELECT COUNT(*) FROM {$P} WHERE option_id = %d AND original_hash = UNHEX(%s)", $optionId, $newHash) === 1);
tl_ok('old parent deleted',
    tl_count("SELECT COUNT(*) FROM {$P} WHERE option_id = %d AND original_hash = UNHEX(%s)", $optionId, $oldHash) === 0);
tl_ok('exactly one discovered row remains', $rowsFor($optionId) === 1);

$carried = $wpdb->get_row($wpdb->prepare(
    "SELECT translated_text, status, provider, created_at FROM {$C}
     WHERE original_hash = UNHEX(%s) AND lang_code = %s", $newHash, $LANG), ARRAY_A);

tl_ok('translation carried to the new hash', $carried !== null);
tl_ok('  text preserved',     ($carried['translated_text'] ?? null) === 'Titre TLTEST');
tl_ok('  status is NEEDS_REVIEW', (int) ($carried['status'] ?? 0) === TranslationStatus::NEEDS_REVIEW);
tl_ok('  provider preserved', ($carried['provider'] ?? null) === 'test-provider');
tl_ok('  created_at preserved', ($carried['created_at'] ?? null) === $oldCreatedAt);

tl_ok('old child row still present (sweep reclaims it, not reconcile)',
    tl_count("SELECT COUNT(*) FROM {$C} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $oldHash, $LANG) === 1);

// Same wiring for the tagline — the original bug report.
tl_section('update_option_blogdescription hook:');

update_option('blogdescription', 'TLTEST Tagline One');
$scanner->scan('blogdescription');
$descId = (int) tl_var("SELECT option_id FROM {$wpdb->options} WHERE option_name = 'blogdescription'");
tl_ok('tagline discovered', $rowsFor($descId) === 1);

update_option('blogdescription', 'TLTEST Tagline Two');
$descHash = strtolower(bin2hex(TL_Key::currentHash('TLTEST Tagline Two')));
tl_ok('tagline hook re-discovered the new text',
    tl_count("SELECT COUNT(*) FROM {$P} WHERE option_id = %d AND original_hash = UNHEX(%s)", $descId, $descHash) === 1);

// ── 4-5. Empty-scope prune, and ignored survivors ────────────────────────────

tl_section('empty-scope prune:');

// "12" is rejected by looksTranslatable(), so scan() reconciles with no records.
update_option('blogname', '12');
tl_ok('untranslatable value prunes the scope', $rowsFor($optionId) === 0, 'got ' . $rowsFor($optionId));

update_option('blogname', 'TLTEST Restored');
$scanner->scan('blogname');
tl_ok('re-discovery works after a prune', $rowsFor($optionId) === 1);

$restoredId = (int) tl_var(
    "SELECT id FROM {$P} WHERE option_id = %d AND status = %d", $optionId, TranslationStatus::SOURCE_DISCOVERED);
$repo->ignoreSource(SourceRef::make('option', $restoredId));

tl_ok('ignored parent is status -1',
    tl_count("SELECT COUNT(*) FROM {$P} WHERE id = %d AND status = %d", $restoredId, TranslationStatus::SOURCE_IGNORED) === 1);

update_option('blogname', '12');
tl_ok('empty reconcile spares the IGNORED parent',
    tl_count("SELECT COUNT(*) FROM {$P} WHERE id = %d", $restoredId) === 1);

// ── 6. Finding (B): shared-by-text ───────────────────────────────────────────

tl_section('finding (B) — two options with identical text share one translation:');

$sharedText = 'TLTEST Shared Branding Text';
update_option('blogname', $sharedText);
update_option('blogdescription', $sharedText);
$scanner->scan('blogname');
$scanner->scan('blogdescription');

$sharedHash = strtolower(bin2hex(TL_Key::currentHash($sharedText)));

$parentCount = tl_count(
    "SELECT COUNT(*) FROM {$P} WHERE original_hash = UNHEX(%s) AND status = %d",
    $sharedHash, TranslationStatus::SOURCE_DISCOVERED);
tl_ok('two parents hold the same hash', $parentCount === 2, "got $parentCount");

$anyId = (int) tl_var(
    "SELECT id FROM {$P} WHERE original_hash = UNHEX(%s) AND status = %d LIMIT 1",
    $sharedHash, TranslationStatus::SOURCE_DISCOVERED);
$repo->saveTranslation(SourceRef::make('option', $anyId), $LANG, 'Texte partagé', TranslationStatus::MACHINE, null);

$childCount = tl_count(
    "SELECT COUNT(*) FROM {$C} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $sharedHash, $LANG);
tl_ok('but only ONE translation row exists (hash-link)', $childCount === 1, "got $childCount");

$map = $repo->fetchAllHashTranslations('tl_option', $LANG);
tl_ok('the map serves both options from one entry',
    ($map[$sharedHash] ?? null) === 'Texte partagé');

// ── 7. FK cascade, split into two assertions ─────────────────────────────────
// A fused assertion cannot distinguish "no FK" from "FK that does not cascade".

tl_section('fk_option_owner cascade:');

$fkExists = tl_count(
    "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND CONSTRAINT_NAME = 'fk_option_owner'",
    DB_NAME, $P);
tl_ok('fk_option_owner constraint exists', $fkExists > 0, "found $fkExists");

$fixtureOption = 'tltest_fixture_option';
add_option($fixtureOption, 'TLTEST Fixture Option Value');
$fixtureId = (int) tl_var("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $fixtureOption);

add_filter('translangs_option_paths', $addFixture = static function (array $list) use ($fixtureOption): array {
    $list[$fixtureOption] = [''];
    return $list;
});
$scanner->scan($fixtureOption);
remove_filter('translangs_option_paths', $addFixture);

tl_ok('fixture option discovered', $rowsFor($fixtureId) === 1, 'got ' . $rowsFor($fixtureId));

$fixtureHash = strtolower(bin2hex(TL_Key::currentHash('TLTEST Fixture Option Value')));
$fixtureRowId = (int) tl_var(
    "SELECT id FROM {$P} WHERE option_id = %d AND status = %d", $fixtureId, TranslationStatus::SOURCE_DISCOVERED);
$repo->saveTranslation(SourceRef::make('option', $fixtureRowId), $LANG, 'Valeur fixture', TranslationStatus::MACHINE, null);

$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_id = %d", $fixtureId));

tl_ok('deleting the option cascades the parent away',
    tl_count("SELECT COUNT(*) FROM {$P} WHERE option_id = %d", $fixtureId) === 0);
tl_ok('the hash-link child SURVIVES (this is the sweep\'s input)',
    tl_count("SELECT COUNT(*) FROM {$C} WHERE original_hash = UNHEX(%s) AND lang_code = %s", $fixtureHash, $LANG) === 1);

tl_done();

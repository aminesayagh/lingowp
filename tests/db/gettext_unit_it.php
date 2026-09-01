<?php

/**
 * GettextUnitRepository — schema shape + round-trip behavior.
 * Run: wp eval-file tests/db/gettext_unit_it.php
 *
 * Uses type="plugin" (a real value — the column is varchar(10), too short
 * for a long test marker) with domain="_test-fixture" (a fake value real
 * scans never produce), so this file's own DML can't collide with real data even
 * outside the rollback (belt-and-suspenders: test_begin() already rolls
 * everything back, this is just extra safety for the option writes below,
 * which the rollback does NOT cover — see _harness.php's isolation contract,
 * point 3 — so this file deletes lingowp_gettext_scanned_domains' own test
 * entry manually before test_done()).
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\TranslationMemorySchema;
use LingoWP\GettextDomains\GettextUnitRepository;

global $wpdb;

test_guard();
test_begin();

$table  = TranslationMemorySchema::gettextUnitTable();
$type   = 'plugin'; // type is varchar(10) — must be a real-shaped value, not a long test marker
$domain = '_test-fixture';
$repo   = new GettextUnitRepository($wpdb);

// ── A. schema shape ──────────────────────────────────────────────────────

test_section('schema shape:');

test_ok('table exists', test_count(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
    DB_NAME,
    $table
) === 1);

$cols = array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
    DB_NAME,
    $table
)));
foreach (['id', 'type', 'domain', 'unit_hash', 'msgid', 'context', 'status', 'last_seen_at', 'created_at', 'updated_at'] as $expected) {
    test_ok("  column $expected exists", in_array($expected, $cols, true), 'got ' . json_encode($cols));
}

// ── B. wasScanned()/markScanned() — the exact P1 regression codex caught ────
// Rows existing must NOT be read as proof a real scan ran (the migration
// pre-seeds ignore rows for domains it never scanned).

test_section('wasScanned() is not fooled by rows existing without a scan:');

test_ok('never touched: not scanned', $repo->wasScanned($type, $domain) === false);

$repo->bulkUpsert($type, $domain, [['msgid' => 'Save', 'context' => null]]);
test_ok('rows exist but markScanned() never called: still not scanned',
    $repo->wasScanned($type, $domain) === false);

$repo->markScanned($type, $domain);
test_ok('markScanned() flips it', $repo->wasScanned($type, $domain) === true);

$repo->markScanned($type, $domain); // idempotent
test_ok('markScanned() twice is a no-op', $repo->wasScanned($type, $domain) === true);

// ── C. bulkUpsert / activeUnits / countActive round-trip ────────────────────

test_section('bulkUpsert() + activeUnits() + countActive():');

$repo->bulkUpsert($type, $domain, [
    ['msgid' => 'Save', 'context' => null],   // already inserted above
    ['msgid' => 'Save', 'context' => 'button'], // same msgid, different context -> distinct row
    ['msgid' => 'save', 'context' => null],   // case differs -> distinct row
]);

$active = $repo->activeUnits($type, $domain);
test_ok('3 distinct rows (context and case both distinguish)', count($active) === 3, 'got ' . count($active));
test_ok('countActive() agrees with activeUnits()', $repo->countActive($type, $domain) === count($active));

$hasContext = false;
foreach ($active as $row) {
    if ($row['msgid'] === 'Save' && $row['context'] === 'button') {
        $hasContext = true;
    }
}
test_ok('context round-trips as a string, not null/empty confusion', $hasContext);

$repo->bulkUpsert($type, $domain, [['msgid' => 'Save', 'context' => null]]); // re-scan, same string
test_ok('re-upserting an unchanged string does not duplicate it',
    $repo->countActive($type, $domain) === 3, 'got ' . $repo->countActive($type, $domain));

// ── D. ignore() ──────────────────────────────────────────────────────────

test_section('ignore():');

$repo->ignore($type, $domain, 'save', null);
test_ok('ignored row drops out of activeUnits()', $repo->countActive($type, $domain) === 2,
    'got ' . $repo->countActive($type, $domain));

$repo->bulkUpsert($type, $domain, [['msgid' => 'save', 'context' => null]]);
test_ok('re-scanning an ignored string does not un-ignore it',
    $repo->countActive($type, $domain) === 2, 'got ' . $repo->countActive($type, $domain));

// ── E. deleteMissing() ───────────────────────────────────────────────────

test_section('deleteMissing():');

// Before this call: Save/null (active), Save/button (active), save/null
// (ignored, from section D). deleteMissing() has no status filter — it
// purges by hash alone, on purpose: a string genuinely gone from a complete
// scan should vanish even if someone had ignored it. So both Save/button
// (active) and save/null (ignored) go, not just the one active row.
$keepHash = GettextUnitRepository::hashHex('Save', null);
$removed  = $repo->deleteMissing($type, $domain, [$keepHash]);
test_ok('2 rows removed (Save/button active + save/null ignored, both missing from the seen set)',
    $removed === 2, "got $removed");
test_ok('only the seen row remains active', $repo->countActive($type, $domain) === 1,
    'got ' . $repo->countActive($type, $domain));

$removedAll = $repo->deleteMissing($type, $domain, []);
test_ok('empty seen set deletes everything for this domain', $removedAll >= 1, "got $removedAll");
test_ok('nothing left for this domain', $repo->countActive($type, $domain) === 0);

// ── F. header search — domainsMatching() + activeUnitsMatching() ──────────
// Both run over the same table/collation (one SELECTs DISTINCT type,domain,
// the other SELECTs msgid,context) so a term that surfaces a component in the
// board list also narrows its expanded string list — no "matches in the list,
// empty when expanded".

test_section('domainsMatching() + activeUnitsMatching():');

$repo->bulkUpsert($type, $domain, [
    ['msgid' => 'Add to Cart', 'context' => null],
    ['msgid' => 'Café details', 'context' => null],
    ['msgid' => '100% off', 'context' => null],
    ['msgid' => 'Checkout now', 'context' => 'button'],
    ['msgid' => 'Hidden string', 'context' => null],
]);
$repo->ignore($type, $domain, 'Hidden string', null);

$key      = "$type:$domain";
$hitMsgids = static fn (string $q): array => array_values(array_map(
    static fn (array $u): string => $u['msgid'],
    $repo->activeUnits($type, $domain, $q)
));

test_ok('domainsMatching: case-insensitive substring hit', isset($repo->domainsMatching('add to cart')[$key]));
test_ok('domainsMatching: no false hit', ! isset($repo->domainsMatching('nonexistent-zzz')[$key]));
test_ok('domainsMatching: an ignored-only match is excluded', ! isset($repo->domainsMatching('Hidden string')[$key]));

test_ok('activeUnits(search): substring hit', $hitMsgids('to cart') === ['Add to Cart'], json_encode($hitMsgids('to cart')));
test_ok('activeUnits(search): ignored row excluded', $hitMsgids('Hidden') === []);
test_ok('activeUnits(no search) still returns everything active',
    count($repo->activeUnits($type, $domain)) === 4, 'got ' . count($repo->activeUnits($type, $domain)));

// esc_like: % in the term is a literal. Unescaped, LIKE '%1%f%' would match
// "100% off" via wildcards; escaped, "1%f" is not a literal substring of it.
test_ok('activeUnits(search): % in the term is escaped, not a wildcard', $hitMsgids('1%f') === [], json_encode($hitMsgids('1%f')));
test_ok('activeUnits(search): literal % substring still matches', $hitMsgids('100% off') === ['100% off'], json_encode($hitMsgids('100% off')));

// Case/accent folding is a property of the column collation — assert only that
// BOTH paths agree, whichever way it folds.
$domHit  = isset($repo->domainsMatching('cafe details')[$key]);
$unitHit = $hitMsgids('cafe details') !== [];
test_ok('case/accent folding is identical across both search paths', $domHit === $unitHit,
    'domainsMatching=' . var_export($domHit, true) . ' activeUnits(search)=' . var_export($unitHit, true));

$repo->markScanned($type, $domain);
test_ok('scannedGroupKeys() reflects markScanned()', in_array($key, $repo->scannedGroupKeys(), true),
    json_encode($repo->scannedGroupKeys()));

// Manual cleanup of the option write — test_begin()'s rollback covers every
// table write above, but NOT update_option() (see this file's own docblock).
// Surgically remove only this file's own entry: the option is a shared,
// site-wide list of every real scanned domain, so delete_option() here would
// wipe real production scan state instead of just this test's fixture.
$scanned = json_decode((string) get_option('lingowp_gettext_scanned_domains', '[]'), true);
$scanned = is_array($scanned) ? array_values(array_diff($scanned, ["$type:$domain"])) : [];
update_option('lingowp_gettext_scanned_domains', wp_json_encode($scanned));

test_done();

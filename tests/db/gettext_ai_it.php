<?php

/**
 * GettextAiQueue — the async "Translate All" queue for the Plugins & Themes
 * board. Bookkeeping lives as columns on wp_lingowp_gettext_unit (no new
 * table, no locale column — see the DB_VERSION 6 migration).
 * Run: wp eval-file tests/db/gettext_ai_it.php
 *
 * The backend is faked (implements TranslationBackendPort) — no HTTP, since
 * GettextAiQueue depends on the interface, not BackendClient (same pattern
 * as tests/db/ai_result_applier_it.php).
 *
 * saveUnit() writes a real `.mo` file under WP_LANG_DIR — outside the DB
 * transaction, so this file cleans those up itself before test_done().
 */

require __DIR__ . '/_harness.php';

use LingoWP\Backend\TranslationBackendPort;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\GettextDomains\GettextAiQueue;
use LingoWP\GettextDomains\GettextDomainRegistry;
use LingoWP\GettextDomains\GettextPoImporter;
use LingoWP\GettextDomains\GettextTemplateGenerator;
use LingoWP\GettextDomains\GettextTranslatedPercentCache;
use LingoWP\GettextDomains\GettextTranslationUnitsService;
use LingoWP\GettextDomains\GettextUnitRepository;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Shared\Text\TranslationKey;

final class FakeGettextBackendPort implements TranslationBackendPort
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

$table  = TranslationMemorySchema::gettextUnitTable();
$type   = 'plugin'; // varchar(10) — real-shaped value, see gettext_unit_it.php's own note
$domain = '_test-ai-fixture';
$repo   = new GettextUnitRepository($wpdb);

$backend = new FakeGettextBackendPort();
$units   = new GettextTranslationUnitsService(new GettextTemplateGenerator(), new GettextPoImporter(), $repo);
$queue   = new GettextAiQueue($wpdb, $backend, $units, new GettextDomainRegistry(), new GettextTranslatedPercentCache(), new OptionLanguageRegistry(), new LanguageMetadataStore());

$writtenMoFiles = [];

// ── A. schema shape ──────────────────────────────────────────────────────

test_section('schema shape (gettext_unit AI columns):');

$cols = array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
    DB_NAME,
    $table
)));
foreach (['norm_hash', 'ai_pending_locales', 'ai_pending_since', 'ai_error'] as $expected) {
    test_ok("  column $expected exists", in_array($expected, $cols, true), 'got ' . json_encode($cols));
}

$keys = array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
    DB_NAME,
    $table
)));
foreach (['idx_ai_pending', 'idx_ai_norm'] as $expected) {
    test_ok("  index $expected exists", in_array($expected, $keys, true), 'got ' . json_encode($keys));
}

// ── B. fixture: two msgctxt variants of the same msgid (share one norm_hash) ─

test_section('fixture:');

$repo->bulkUpsert($type, $domain, [
    ['msgid' => 'Post', 'context' => 'noun'],
    ['msgid' => 'Post', 'context' => 'verb'],
    ['msgid' => 'Save', 'context' => null],
]);
$repo->markScanned($type, $domain);

$uhNoun = GettextUnitRepository::hashHex('Post', 'noun');
$uhVerb = GettextUnitRepository::hashHex('Post', 'verb');
$uhSave = GettextUnitRepository::hashHex('Save', null);

test_ok('3 rows seeded', $repo->countActive($type, $domain) === 3, 'got ' . $repo->countActive($type, $domain));

// ── C. submit() stamps ai_pending_locales, never touches other rows ─────────

test_section('submit():');

$unitsArg = [
    ['type' => $type, 'domain' => $domain, 'msgid' => 'Post', 'context' => 'noun', 'unit_hash' => $uhNoun],
    ['type' => $type, 'domain' => $domain, 'msgid' => 'Post', 'context' => 'verb', 'unit_hash' => $uhVerb],
];

$r1 = $queue->submit($unitsArg, 'fr_FR');
test_ok('submit() ok for fr_FR', $r1['error'] === null, json_encode($r1));
test_ok('submit() reports 2 rows actually stamped', $r1['submitted'] === 2, json_encode($r1));

// Regression for the live 422: the backend's request model requires a
// `languages` map (one entry per target_langs member, each with a
// `provider`) or it rejects the whole submit with "field required".
$sentPayload = $backend->submitted[0] ?? [];
test_ok('submitTasks() payload includes languages', array_key_exists('languages', $sentPayload), json_encode($sentPayload));
test_ok('languages has an entry for the submitted locale',
    isset($sentPayload['languages']['fr_FR']['provider']), json_encode($sentPayload['languages'] ?? null));

$row = $wpdb->get_row($wpdb->prepare(
    "SELECT ai_pending_locales, ai_pending_since, ai_error, norm_hash FROM {$table} WHERE type=%s AND domain=%s AND unit_hash=UNHEX(%s)",
    $type,
    $domain,
    $uhNoun
), ARRAY_A);
test_ok('ai_pending_locales stamped with fr_FR', $row['ai_pending_locales'] === 'fr_FR', json_encode($row));
test_ok('ai_pending_since stamped', $row['ai_pending_since'] !== null);
$normHexRow = $wpdb->get_var($wpdb->prepare(
    "SELECT LOWER(HEX(norm_hash)) FROM {$table} WHERE type=%s AND domain=%s AND unit_hash=UNHEX(%s)",
    $type,
    $domain,
    $uhNoun
));
test_ok('norm_hash is the normalized (not byte-exact) hash of "Post"',
    $normHexRow === bin2hex(TranslationKey::currentHash('Post')), "got $normHexRow");

$r2 = $queue->submit($unitsArg, 'en_GB');
test_ok('submit() ok for en_GB', $r2['error'] === null, json_encode($r2));

$row = $wpdb->get_row($wpdb->prepare(
    "SELECT ai_pending_locales FROM {$table} WHERE type=%s AND domain=%s AND unit_hash=UNHEX(%s)",
    $type,
    $domain,
    $uhNoun
), ARRAY_A);
test_ok('a second locale appends to the pipe-list', $row['ai_pending_locales'] === 'fr_FR|en_GB', json_encode($row));

test_ok('the untouched "Save" row has no pending locales', (string) $wpdb->get_var($wpdb->prepare(
    "SELECT ai_pending_locales FROM {$table} WHERE type=%s AND domain=%s AND unit_hash=UNHEX(%s)",
    $type,
    $domain,
    $uhSave
)) === '');

test_ok('hasAnyPending() is true', $queue->hasAnyPending() === true);

// ── C2. hasActiveUnit() + submit() against a row that doesn't exist ────────
// The scope:'unit' REST path guards on hasActiveUnit() before touching the
// backend; if that guard were ever bypassed, submit() must still report 0
// stamped so a phantom unit is never counted / polled / matched.

test_section('missing-row guard:');

$phantom = GettextUnitRepository::hashHex('Never scanned string', null);
test_ok('hasActiveUnit() false for a hash with no row', $queue->hasActiveUnit($type, $domain, $phantom) === false);
test_ok('hasActiveUnit() true for a real active row', $queue->hasActiveUnit($type, $domain, $uhSave) === true);

$repo->ignore($type, $domain, 'Save', null); // nothing below touches Save
test_ok('hasActiveUnit() false once the row is ignored (status = -1)', $queue->hasActiveUnit($type, $domain, $uhSave) === false);

$rPhantom = $queue->submit(
    [['type' => $type, 'domain' => $domain, 'msgid' => 'Never scanned string', 'context' => null, 'unit_hash' => $phantom]],
    'fr_FR'
);
test_ok('submit() for a missing row reports 0 stamped', $rPhantom['submitted'] === 0, json_encode($rPhantom));

// ── D. collectOnce(): success fans out to both context variants ────────────

test_section('collectOnce() — success (fr_FR), msgctxt fan-out:');

$backend->pending = [
    ['source_hash' => bin2hex(TranslationKey::currentHash('Post')), 'target_lang' => 'fr_FR', 'output' => 'Article', 'error' => null],
];
$queue->collectOnce();

$moPath = WP_LANG_DIR . '/plugins/' . $domain . '-fr_FR.mo';
$writtenMoFiles[] = $moPath;

test_ok('.mo file written for fr_FR', file_exists($moPath), $moPath);

if (! class_exists('MO')) {
    require_once ABSPATH . WPINC . '/pomo/mo.php';
}
$mo = new \MO();
$mo->import_from_file($moPath);
$noun = (string) ($mo->entries["noun\x04Post"]->translations[0] ?? '');
$verb = (string) ($mo->entries["verb\x04Post"]->translations[0] ?? '');
test_ok('noun-context variant got the translation', $noun === 'Article', "got $noun");
test_ok('verb-context variant ALSO got the translation (same norm_hash, accepted fan-out)', $verb === 'Article', "got $verb");

$row = $wpdb->get_row($wpdb->prepare(
    "SELECT ai_pending_locales, ai_pending_since, ai_error FROM {$table} WHERE type=%s AND domain=%s AND unit_hash=UNHEX(%s)",
    $type,
    $domain,
    $uhNoun
), ARRAY_A);
test_ok('fr_FR removed from the pending list, en_GB remains', $row['ai_pending_locales'] === 'en_GB', json_encode($row));
test_ok('ai_pending_since still set (en_GB still pending)', $row['ai_pending_since'] !== null);
test_ok('ai_error is NULL after a success', $row['ai_error'] === null);
test_ok('ackResults() was called', count($backend->acked) === 1);

// ── E. collectOnce(): failure clears pending, sets ai_error, leaves .mo alone

test_section('collectOnce() — failure (en_GB):');

$backend->pending = [
    ['source_hash' => bin2hex(TranslationKey::currentHash('Post')), 'target_lang' => 'en_GB', 'output' => null, 'error' => 'ERR_RATE_LIMITED'],
];
$queue->collectOnce();

$enGbMoPath = WP_LANG_DIR . '/plugins/' . $domain . '-en_GB.mo';
test_ok('.mo for en_GB was NOT created on failure', ! file_exists($enGbMoPath));

$row = $wpdb->get_row($wpdb->prepare(
    "SELECT ai_pending_locales, ai_pending_since, ai_error FROM {$table} WHERE type=%s AND domain=%s AND unit_hash=UNHEX(%s)",
    $type,
    $domain,
    $uhNoun
), ARRAY_A);
test_ok('en_GB removed, pending list now empty', $row['ai_pending_locales'] === '', json_encode($row));
test_ok('ai_pending_since cleared (list is empty)', $row['ai_pending_since'] === null);
test_ok('ai_error set to the failure code', $row['ai_error'] === 'ERR_RATE_LIMITED', json_encode($row));

test_ok('hasAnyPending() is false once every row settles', $queue->hasAnyPending() === false);

// pendingForList() for a locale that isn't in flight: no `pending`, but the
// just-set ai_error surfaces (the board shows a persistent "AI failed"
// marker). Checked HERE, before any resubmit — submit() deliberately clears
// ai_error.
$pfFailed = $queue->pendingForList($type, $domain, 'fr_FR');
test_ok('a settled failure exposes {pending:false, error:<code>}',
    ($pfFailed[$uhNoun]['pending'] ?? 'x') === false && ($pfFailed[$uhNoun]['error'] ?? null) === 'ERR_RATE_LIMITED',
    json_encode($pfFailed[$uhNoun] ?? null));

// ── F. pendingForList() — the board read path ────────────────────────────

test_section('pendingForList():');

$queue->submit($unitsArg, 'de_DE');
$pf = $queue->pendingForList($type, $domain, 'de_DE');
test_ok('pending true for the locale just submitted', ($pf[$uhNoun]['pending'] ?? null) === true, json_encode($pf[$uhNoun] ?? null));
// Also proves submit() cleared the stale ai_error set back in section E.
test_ok('error key is present and null while pending',
    array_key_exists($uhNoun, $pf) && array_key_exists('error', $pf[$uhNoun]) && $pf[$uhNoun]['error'] === null,
    json_encode($pf[$uhNoun] ?? null));

// ── G. a stale (>6h) pending stamp is not "pending" and is dropped from the map ──

test_section('isFreshPending / stale pending:');

$wpdb->query($wpdb->prepare(
    "UPDATE {$table} SET ai_pending_since = %s WHERE type=%s AND domain=%s AND unit_hash=UNHEX(%s)",
    gmdate('Y-m-d H:i:s', time() - 7 * 3600),
    $type,
    $domain,
    $uhVerb
));
$pfStale = $queue->pendingForList($type, $domain, 'de_DE');
// A row that is neither fresh-pending nor errored has nothing for the board
// to show, so pendingForList() omits it entirely.
test_ok('a 7h-old pending stamp is dropped from the map', ! array_key_exists($uhVerb, $pfStale), json_encode($pfStale));

// ── H. resubmitting a stale locale refreshes ai_pending_since ──────────────
// Regression for the bug where COALESCE(ai_pending_since, now) left a stale
// anchor untouched on resubmit, so a just-submitted locale still read as
// "not pending" and got resubmitted on every subsequent click.

test_section('resubmit after staleness refreshes ai_pending_since:');

$verbArg = [['type' => $type, 'domain' => $domain, 'msgid' => 'Post', 'context' => 'verb', 'unit_hash' => $uhVerb]];
$r3      = $queue->submit($verbArg, 'de_DE');
test_ok('resubmit ok', $r3['error'] === null, json_encode($r3));

$pfResubmit = $queue->pendingForList($type, $domain, 'de_DE');
test_ok('de_DE reads as freshly pending again after resubmit',
    ($pfResubmit[$uhVerb]['pending'] ?? null) === true, json_encode($pfResubmit[$uhVerb] ?? null));

// ── cleanup: files outside the transaction, the option write above ────────

foreach ($writtenMoFiles as $f) {
    if (file_exists($f)) {
        @unlink($f);
    }
}
$scanned = json_decode((string) get_option('lingowp_gettext_scanned_domains', '[]'), true);
$scanned = is_array($scanned) ? array_values(array_diff($scanned, ["$type:$domain"])) : [];
update_option('lingowp_gettext_scanned_domains', wp_json_encode($scanned));

test_done();

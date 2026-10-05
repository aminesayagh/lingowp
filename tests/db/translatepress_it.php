<?php

/**
 * lingowp-translatepress add-on coverage (PLAN.md in that add-on, §3), plus
 * the two LingoWP core changes it needs (saveTranslation() result, the
 * board's "imported" origin).
 * Run: wp eval-file tests/db/translatepress_it.php
 *
 * TEMPORARY HOME, by request: this exercises the separate
 * lingowp-translatepress add-on's classes (LingoWPTranslatePress\*), kept here
 * so it runs via one `composer test:db` during active development. Move it to
 * lingowp-translatepress/tests/ once the add-on stabilizes.
 *
 * Needs: lingowp and lingowp-translatepress active; TranslatePress installed
 * (its tables and trp_settings exist) but INACTIVE — when active it runs a
 * machine-translation job at shutdown, after the rollback.
 *
 * Isolation: everything runs in the harness transaction (always rolled back).
 * Fixture rows go into the existing en_US → fr_FR TranslatePress tables, which
 * are emptied first inside the transaction. Gettext files go to a temporary
 * folder deleted at shutdown. No DDL.
 */

require __DIR__ . '/_harness.php';

use LingoWP\Backend\AddonSiteMatcher;
use LingoWP\Board\CollectionBoard;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\GettextDomains\GettextDomainRegistry;
use LingoWP\GettextDomains\InstalledGettextComponentDiscovery;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Shared\Text\TranslationKey;
use LingoWPTranslatePress\Admin\MigrationJob;
use LingoWPTranslatePress\Admin\MigrationRestController;
use LingoWPTranslatePress\Admin\MigrationState;
use LingoWPTranslatePress\DictionaryImport;
use LingoWPTranslatePress\GettextImport;
use LingoWPTranslatePress\Importer;
use LingoWPTranslatePress\ImportStopped;
use LingoWPTranslatePress\LanguageImport;

global $wpdb;

test_guard();

if (!class_exists(DictionaryImport::class)) {
    fwrite(STDERR, "SKIPPED: lingowp-translatepress is not active; this suite needs it.\n");
    exit(0);
}

$LANG = 'fr_FR';
$DICT = Importer::dictionaryTable($wpdb, 'en_US', $LANG);
$GT   = $wpdb->prefix . 'trp_gettext_fr_fr';
$GO   = $wpdb->prefix . 'trp_gettext_original_strings';

if (!is_array(get_option('trp_settings')) || !DictionaryImport::tableExists($wpdb, $DICT)) {
    fwrite(STDERR, "SKIPPED: TranslatePress tables not found (activate TranslatePress once to create them, then deactivate it).\n");
    exit(0);
}

if (class_exists('TRP_Translate_Press')) {
    fwrite(STDERR, "REFUSING TO RUN: TranslatePress is active. Deactivate it: it runs a machine-translation job at shutdown, after this suite's rollback.\n");
    exit(1);
}

$HP = TranslationMemorySchema::parentTables()['html'];
$PP = TranslationMemorySchema::parentTables()['post'];
$MP = TranslationMemorySchema::parentTables()['meta'];
$touched = array_merge(
    [$DICT, $GT, $GO, $wpdb->posts, $wpdb->options],
    array_values(TranslationMemorySchema::parentTables()),
    array_values(TranslationMemorySchema::childTables()),
    array_map(static function (string $t) use ($wpdb): string {
        return $wpdb->prefix . 'actionscheduler_' . $t;
    }, ['actions', 'claims', 'groups', 'logs'])
);
foreach ($touched as $table) {
    $engine = (string) $wpdb->get_var($wpdb->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $table
    ));
    if ($engine !== '' && strtolower($engine) !== 'innodb') {
        fwrite(STDERR, "REFUSING TO RUN: {$table} uses {$engine}; the rollback only covers transactional (InnoDB) tables.\n");
        exit(1);
    }
}

test_begin();

$now = current_time('mysql');

/** Every file under LingoWP's live .mo folder with its size and mtime; must not change. */
$liveMoFiles = static function (): array {
    $root  = \LingoWP\GettextDomains\GettextPoImporter::baseDir();
    $files = [];
    foreach (glob($root . '/*/*') ?: [] as $file) {
        $files[$file] = filesize($file) . ':' . filemtime($file);
    }
    ksort($files);
    return $files;
};
$liveMoBefore = $liveMoFiles();

// ── helpers ──────────────────────────────────────────────────────────────────

/** An html source unit (hash-shared, no owner row needed). */
$unit = static function (string $text, int $status = 0) use ($HP, $now): int {
    return test_insert($HP, [
        'selector'      => 'zq-trpit',
        'original_hash' => TranslationKey::currentHash($text),
        'original_text' => $text,
        'status'        => $status,
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);
};

/** A TranslatePress dictionary row; ids grow, so later calls are "newer" rows. */
$trp = static function (string $original, string $translated, int $status = 1, int $blockType = 0) use ($DICT): int {
    return test_insert($DICT, ['original' => $original, 'translated' => $translated, 'status' => $status, 'block_type' => $blockType]);
};

$resetTrp = static function () use ($wpdb, $DICT): void {
    $wpdb->query("DELETE FROM {$DICT}"); // phpcs:ignore WordPress.DB
};

/** The fr_FR translation row of a hash-shared text, or null. */
$tr = static function (string $text, string $tableKey = 'html') use ($wpdb, $LANG): ?array {
    $child = TranslationMemorySchema::childTables()[$tableKey];
    $row   = $wpdb->get_row($wpdb->prepare(
        "SELECT translated_text, status, provider FROM {$child} WHERE original_hash = UNHEX(%s) AND lang_code = %s", // phpcs:ignore WordPress.DB
        bin2hex(TranslationKey::currentHash($text)),
        $LANG
    ), ARRAY_A);

    return is_array($row) ? $row : null;
};

$import = static function (bool $dryRun = false, bool $overwrite = false) use ($wpdb, $DICT, $LANG): DictionaryImport {
    $run = new DictionaryImport($wpdb, $dryRun, $overwrite);
    $run->run($DICT, $LANG);

    return $run;
};

$makePost = static function () use ($wpdb, $now): int {
    return test_insert($wpdb->posts, [
        'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => $now, 'post_content' => '',
        'post_title' => 'LINGOWP_TEST ZQTRPIT', 'post_excerpt' => '', 'post_status' => 'publish',
        'comment_status' => 'closed', 'ping_status' => 'closed',
        'post_name' => 'lingowp-test-zqtrpit-' . wp_generate_password(8, false), 'post_password' => '',
        'post_modified' => $now, 'post_modified_gmt' => $now, 'post_type' => 'post',
    ]);
};

/** Make every INSERT/SELECT containing $needle fail, run $fn, then restore. */
$withFailingQuery = static function (string $needle, callable $fn) use ($wpdb) {
    $sabotage = static function (string $query) use ($needle): string {
        return strpos($query, $needle) !== false ? 'SELECT * FROM zq_trpit_no_such_table' : $query;
    };
    add_filter('query', $sabotage);
    $suppressed = $wpdb->suppress_errors(true);
    try {
        return $fn();
    } finally {
        remove_filter('query', $sabotage);
        $wpdb->suppress_errors($suppressed);
    }
};

// ── 1. Core: saveTranslation() reports its result ────────────────────────────

test_section('core — saveTranslation() returns whether the write succeeded:');

$repo   = lingowp_source_repository();
$coreId = $unit('ZQTRPIT core save');
$ref    = SourceRef::make('html', $coreId);

test_ok('a normal save returns true', $repo->saveTranslation($ref, $LANG, 'ZQ enregistré', TranslationStatus::MACHINE, null) === true);
test_ok('a save the database refuses returns false', $withFailingQuery('ZQTRPIT-FAILWRITE', static function () use ($repo, $ref, $LANG) {
    return $repo->saveTranslation($ref, $LANG, 'ZQTRPIT-FAILWRITE', TranslationStatus::MACHINE, null);
}) === false);
test_ok('a hash-shared save whose source row is gone returns false', $repo->saveTranslation(SourceRef::make('html', 999999999), $LANG, 'x', 1, null) === false);

// ── 2. Core: the board's origin label ────────────────────────────────────────

test_section('core — board origin for imported translations:');

$board   = new CollectionBoard($repo, new OptionLanguageRegistry());
$present = static function (?string $provider, int $status) use ($board, $coreId): array {
    return $board->presentText('html', [
        'id' => $coreId, 'translated_text' => 'x', 'status' => $status, 'provider' => $provider,
        'original_text' => 'ZQTRPIT core save', 'original_hash' => null,
    ], '');
};

$imported = $present('translatepress', TranslationStatus::MACHINE);
test_ok('provider translatepress → origin "imported"', $imported['origin'] === 'imported', 'got ' . var_export($imported['origin'], true));
test_ok('provider translatepress is never flagged as AI', $imported['is_ai'] === false);
test_ok('an AI provider is still origin "ai"', $present('ai', TranslationStatus::MACHINE)['origin'] === 'ai');
test_ok('no provider is still origin "manual"', $present(null, TranslationStatus::REVIEWED)['origin'] === 'manual');

test_section('core — double-clicking "Imported" makes the translation manual:');

$manualId  = $unit('ZQTRPIT make manual');
$manualRef = SourceRef::make('html', $manualId);
$repo->saveTranslation($manualRef, $LANG, 'ZQ rendu manuel', TranslationStatus::NEEDS_REVIEW, 'translatepress');

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admins[0] ?? 0));
$request = new \WP_REST_Request('POST', '/lingowp/v1/texts/' . rawurlencode($manualRef->format()) . '/status');
$request->set_param('action', 'manual');
$request->set_param('language', $LANG);
$response = rest_do_request($request);
$row      = $tr('ZQTRPIT make manual');

test_ok('the board\'s "manual" action succeeds', $response->get_status() === 200 && ($response->get_data()['ok'] ?? false) === true, 'status ' . $response->get_status());
test_ok('it clears the provider, so the board shows it as manual', $row !== null && $row['provider'] === null);
test_ok('it keeps the text and the status (still needs review)', ($row['translated_text'] ?? '') === 'ZQ rendu manuel' && (int) $row['status'] === TranslationStatus::NEEDS_REVIEW);
wp_set_current_user(0);
$request = new \WP_REST_Request('POST', '/lingowp/v1/texts/' . rawurlencode($manualRef->format()) . '/status');
$request->set_param('action', 'manual');
test_ok('a visitor can\'t use it', in_array(rest_do_request($request)->get_status(), [401, 403], true));
wp_set_current_user((int) ($admins[0] ?? 0));
$request = new \WP_REST_Request('POST', '/lingowp/v1/texts/' . rawurlencode($manualRef->format()) . '/status');
$request->set_param('action', 'manual');
$request->set_param('language', $LANG);
$refused = $withFailingQuery('SET provider = NULL', static function () use ($request): int {
    return rest_do_request($request)->get_status();
});
test_ok('a refused update returns an error, not ok (Codex #9)', $refused === 500);
wp_set_current_user(0);

// ── 3. Content: statuses, text forms, existing, overwrite, dry run ───────────

test_section('content — statuses and which rows are used:');

$resetTrp();
foreach (['one', 'two', 'three', 'zero', 'deprecated'] as $name) {
    $unit("ZQTRPIT status {$name}");
}
$trp('ZQTRPIT status one', 'ZQ statut un', 1);
$trp('ZQTRPIT status two', 'ZQ statut deux', 2);
$trp('ZQTRPIT status three', 'ZQ statut trois', 3);
$trp('ZQTRPIT status zero', 'ZQ statut zéro', 0);
$trp('ZQTRPIT status deprecated', 'ZQ bloc ancien', 1, 2);

$dry = $import(true);
test_ok('dry run counts what it would import', $dry->counts()['imported'] === 3, 'imported=' . $dry->counts()['imported']);
test_ok('dry run writes nothing', $tr('ZQTRPIT status one') === null);

$run = $import();
$one = $tr('ZQTRPIT status one');
test_ok('status 1 → machine, provider translatepress', $one !== null && (int) $one['status'] === TranslationStatus::MACHINE && $one['provider'] === 'translatepress' && $one['translated_text'] === 'ZQ statut un');
test_ok('status 2 → reviewed', (int) ($tr('ZQTRPIT status two')['status'] ?? 0) === TranslationStatus::REVIEWED);
test_ok('status 3 → needs review (D1)', (int) ($tr('ZQTRPIT status three')['status'] ?? 0) === TranslationStatus::NEEDS_REVIEW);
test_ok('status 0 is skipped', $tr('ZQTRPIT status zero') === null);
test_ok('block_type 2 is skipped', $tr('ZQTRPIT status deprecated') === null);
test_ok('the report counts the status-3 row as similar', $run->counts()['similar'] === 1);

test_section('content — compact index (Codex 4th pass):');

$packSlot   = new \ReflectionMethod(DictionaryImport::class, 'packSlot');
$unpackSlot = new \ReflectionMethod(DictionaryImport::class, 'unpackSlot');
$packSlot->setAccessible(true);
$unpackSlot->setAccessible(true);
test_ok('a source row id past 32 bits survives the compact slot (no save on the wrong row)',
    $unpackSlot->invoke(null, $packSlot->invoke(null, 3, 4294967297, 1)) === [3, 4294967297, 1]);

$indexer   = new DictionaryImport($wpdb, true, false);
$indexSlot = new \ReflectionMethod(DictionaryImport::class, 'indexSlot');
$indexSlot->setAccessible(true);
$pieceUnitsProp = new \ReflectionProperty(DictionaryImport::class, 'pieceUnits');
$pieceUnitsProp->setAccessible(true);
$indexSlot->invoke($indexer, 1, str_repeat('w {1}x{/1} ', 33000));
test_ok('a unit with more pieces than a reference can hold is never assembled (not half-assembled)', !isset($pieceUnitsProp->getValue($indexer)[1]));

$started = microtime(true);
for ($i = 0; $i < 20000; $i++) {
    $indexSlot->invoke($indexer, 100 + $i, 'ZQTRPIT the same text everywhere');
}
$lookupProp = new \ReflectionProperty(DictionaryImport::class, 'lookup');
$lookupProp->setAccessible(true);
$targets = 0;
foreach ($lookupProp->getValue($indexer) as $found) {
    $targets = max($targets, count((array) $found));
}
test_ok('20,000 slots sharing one text index in linear time (no copying per slot)', $targets === 20000 && microtime(true) - $started < 5.0,
    sprintf('%.2f s, %d targets', microtime(true) - $started, $targets));

test_section('content — text forms (README §3.2):');

$resetTrp();
$unit("ZQTRPIT Don't stop -- now");
$trp("ZQTRPIT Don’t stop — now", 'ZQ Ne t’arrête pas');
$unit('ZQTRPIT Tom & Jerry');
$trp('ZQTRPIT Tom &#038; Jerry', 'ZQ Tom &amp; Jerry');
$unit("ZQTRPIT edge\xc2\xa0");
$trp('ZQTRPIT edge', 'ZQ bord');
$import();

test_ok('a curly-quote original matches a raw straight-quote unit', ($tr("ZQTRPIT Don't stop -- now")['translated_text'] ?? null) === 'ZQ Ne t’arrête pas');
test_ok('an &#038; original matches a plain & unit', $tr('ZQTRPIT Tom & Jerry') !== null);
test_ok('translations are stored entity-decoded', ($tr('ZQTRPIT Tom & Jerry')['translated_text'] ?? null) === 'ZQ Tom & Jerry');
test_ok('a unit with an edge no-break space matches TranslatePress\'s trimmed original', ($tr("ZQTRPIT edge\xc2\xa0")['translated_text'] ?? null) === 'ZQ bord');

test_section('content — existing translations and --overwrite:');

$resetTrp();
$keptId = $unit('ZQTRPIT kept');
$repo->saveTranslation(SourceRef::make('html', $keptId), $LANG, 'ZQ ancien', TranslationStatus::REVIEWED, null);
$trp('ZQTRPIT kept', 'ZQ nouveau');

$run = $import();
test_ok('an existing translation is kept by default', ($tr('ZQTRPIT kept')['translated_text'] ?? null) === 'ZQ ancien');
test_ok('the report counts it as already translated', $run->counts()['already'] === 1);
$import(false, true);
test_ok('--overwrite replaces it', ($tr('ZQTRPIT kept')['translated_text'] ?? null) === 'ZQ nouveau');

test_section('content — ignored sources (README §3.2):');

$resetTrp();
$unit('ZQTRPIT ignored html', TranslationStatus::SOURCE_IGNORED);
$trp('ZQTRPIT ignored html', 'ZQ ignoré');

$postA = $makePost();
$postB = $makePost();
$mixed = 'ZQTRPIT ignored in one post only';
foreach ([[$postA, 0], [$postB, TranslationStatus::SOURCE_IGNORED]] as [$postId, $status]) {
    test_insert($PP, ['post_id' => $postId, 'field' => 'post_content', 'original_hash' => TranslationKey::currentHash($mixed), 'original_text' => $mixed, 'status' => $status, 'created_at' => $now, 'updated_at' => $now]);
}
$trp($mixed, 'ZQ ignoré dans un article');

$run = $import();
test_ok('an ignored source gets no translation', $tr('ZQTRPIT ignored html') === null);
test_ok('a hash with one ignored and one normal occurrence gets no translation', $tr($mixed, 'post') === null);
test_ok('the report counts both as ignored', $run->counts()['ignored'] === 2, 'ignored=' . $run->counts()['ignored']);

test_section('content — per-row sources (meta):');

$resetTrp();
$metaPost = $makePost();
$metaText = 'ZQTRPIT meta text';
$metaIds  = [];
foreach (['zq_trpit_key_a', 'zq_trpit_key_b'] as $metaKey) {
    $metaIds[] = test_insert($MP, [
        'post_id' => $metaPost, 'meta_key' => $metaKey, 'meta_key_hash' => hash('sha256', $metaKey, true),
        'context' => null, 'context_hash' => hash('sha256', '', true),
        'original_hash' => TranslationKey::currentHash($metaText), 'original_text' => $metaText,
        'status' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);
}
$trp($metaText, 'ZQ texte méta');
$import();
test_ok('each meta row with the text gets its own translation', test_count(
    "SELECT COUNT(*) FROM {$MP}_translation WHERE parent_id IN (%d, %d) AND lang_code = %s AND translated_text = %s",
    $metaIds[0], $metaIds[1], $LANG, 'ZQ texte méta'
) === 2);

test_section('content — conflicts (D2: the oldest row wins):');

$resetTrp();
$unit('ZQTRPIT Conflict');
$trp('ZQTRPIT Conflict', 'ZQ premier');
$trp('ZQTRPIT conflict', 'ZQ second');
$run = $import();
test_ok('the oldest TranslatePress row wins', ($tr('ZQTRPIT Conflict')['translated_text'] ?? null) === 'ZQ premier');
test_ok('the conflict is reported', $run->counts()['conflicts'] === 1 && count($run->conflictSamples()) === 1);

test_section('content — same checks as a manual save:');

$resetTrp();
$unit('ZQTRPIT [gallery] lost');
$trp('ZQTRPIT [gallery] lost', 'ZQ perdu');
$run = $import();
test_ok('a translation that drops a shortcode is not saved', $tr('ZQTRPIT [gallery] lost') === null);
test_ok('it is reported as a failed check', $run->counts()['failed_checks'] === 1);

// ── 4. Markup rows and assembled pieces ──────────────────────────────────────

test_section('content — markup rows (block_type 1, README §3.3b):');

$resetTrp();
$unit('ZQTRPIT See {1}this{/1} now');
$trp('ZQTRPIT See <a href="/x">this</a> now', 'ZQ Voir <a href="/x">ceci</a> maintenant', 1, 1);
$unit('ZQTRPIT {1}first{/1} and {2}second{/2}');
$trp('ZQTRPIT <a href="/a">first</a> and <a href="/b">second</a>', 'ZQ <a href="/b">deuxième</a> et <a href="/a">premier</a>', 1, 1);
$unit('ZQTRPIT keep {1}bold{/1} only');
$trp('ZQTRPIT keep <strong>bold</strong> only', 'ZQ garder <strong>gras</strong> <em>seulement</em>', 1, 1);

$run = $import();
test_ok('a markup row imports with LingoWP placeholders', ($tr('ZQTRPIT See {1}this{/1} now')['translated_text'] ?? null) === 'ZQ Voir {1}ceci{/1} maintenant');
test_ok('swapped links keep each link on its own words', ($tr('ZQTRPIT {1}first{/1} and {2}second{/2}')['translated_text'] ?? null) === 'ZQ {2}deuxième{/2} et {1}premier{/1}', 'got ' . var_export($tr('ZQTRPIT {1}first{/1} and {2}second{/2}')['translated_text'] ?? null, true));
test_ok('an extra tag is a markup mismatch, not saved', $tr('ZQTRPIT keep {1}bold{/1} only') === null);
test_ok('the mismatch is reported', $run->counts()['mismatch'] === 1);

$resetTrp();
$unit('ZQTRPIT order {1}matters not{/1}');
$trp('ZQTRPIT order <a class="zq" href="/o">matters not</a>', 'ZQ ordre <a href="/o" class="zq">sans importance</a>', 1, 1);
$import();
test_ok('the same tag with its attributes in another order still matches (Codex #10)', ($tr('ZQTRPIT order {1}matters not{/1}')['translated_text'] ?? null) === 'ZQ ordre {1}sans importance{/1}');

test_section('content — assembled pieces (README §3.3c):');

$resetTrp();
$unit('ZQTRPIT read {1}ZQTRPIT the guide{/1} ZQTRPIT today');
$trp('ZQTRPIT read', 'ZQ lisez');
$trp('ZQTRPIT the guide', 'ZQ le guide');
$trp('ZQTRPIT today', 'ZQ aujourd’hui');
$unit('ZQTRPIT half {1}ZQTRPIT missing piece{/1}');
$trp('ZQTRPIT half', 'ZQ moitié');
$unit('ZQTRPIT direct {1}ZQTRPIT wins{/1}');
$trp('ZQTRPIT direct', 'ZQ assemblé');
$trp('ZQTRPIT wins', 'ZQ gagne');
$trp('ZQTRPIT direct <b>ZQTRPIT wins</b>', 'ZQ direct <b>gagne</b>', 2, 1);

$run       = $import();
$assembled = $tr('ZQTRPIT read {1}ZQTRPIT the guide{/1} ZQTRPIT today');
test_ok('every piece translated → assembled with the source spacing', ($assembled['translated_text'] ?? null) === 'ZQ lisez {1}ZQ le guide{/1} ZQ aujourd’hui', 'got ' . var_export($assembled['translated_text'] ?? null, true));
test_ok('an assembled translation is saved as needs review', (int) ($assembled['status'] ?? 0) === TranslationStatus::NEEDS_REVIEW);
test_ok('one missing piece → the unit is left alone', $tr('ZQTRPIT half {1}ZQTRPIT missing piece{/1}') === null);
test_ok('a direct match beats an assembled one', ($tr('ZQTRPIT direct {1}ZQTRPIT wins{/1}')['translated_text'] ?? null) === 'ZQ direct {1}gagne{/1}');
test_ok('the report counts one assembled translation', $run->counts()['assembled'] === 1);
test_ok('a unit with only some pieces translated is counted (Codex #11)', $run->counts()['incomplete'] === 1);

$resetTrp();
$unit('ZQTRPIT piece {1}ZQTRPIT twice{/1}');
$trp('ZQTRPIT piece', 'ZQ morceau');
$trp('ZQTRPIT twice', 'ZQ deux fois');
$trp('ZQTRPIT Twice', 'ZQ autrement');
$run = $import();
test_ok('two different translations of one piece: the oldest is used, the conflict reported (Codex #11)', ($tr('ZQTRPIT piece {1}ZQTRPIT twice{/1}')['translated_text'] ?? null) === 'ZQ morceau {1}ZQ deux fois{/1}' && $run->counts()['conflicts'] === 1);

$resetTrp();
$preId = $unit('ZQTRPIT pre {1}ZQTRPIT existing{/1}');
$repo->saveTranslation(SourceRef::make('html', $preId), $LANG, 'ZQ avant {1}existant{/1}', TranslationStatus::REVIEWED, null);
$trp('ZQTRPIT pre', 'ZQ pré');
$trp('ZQTRPIT existing', 'ZQ là');
$import();
test_ok('a translation from before the run is kept over an assembly', ($tr('ZQTRPIT pre {1}ZQTRPIT existing{/1}')['translated_text'] ?? null) === 'ZQ avant {1}existant{/1}');
$import(false, true);
test_ok('--overwrite replaces it with the assembly', ($tr('ZQTRPIT pre {1}ZQTRPIT existing{/1}')['translated_text'] ?? null) === 'ZQ pré {1}ZQ là{/1}');

// ── 5. Failures stop the run ─────────────────────────────────────────────────

test_section('content — failures stop the run:');

$resetTrp();
$unit('ZQTRPIT fail write');
$trp('ZQTRPIT fail write', 'ZQTRPIT-FAILWRITE');
$stopped = $withFailingQuery('ZQTRPIT-FAILWRITE', static function () use ($import): bool {
    try {
        $import();
    } catch (ImportStopped $e) {
        return true;
    }
    return false;
});
test_ok('a failed write stops the import', $stopped);

$stopped = $withFailingQuery("FROM {$DICT}", static function () use ($import): bool {
    try {
        $import();
    } catch (ImportStopped $e) {
        return true;
    }
    return false;
});
test_ok('a failed read of the dictionary stops the import', $stopped);

$resetTrp();
$unit('ZQTRPIT context read');
$trp('ZQTRPIT context read', 'ZQ lecture du contexte');
$stopped = $withFailingQuery("SELECT original_text FROM {$HP} WHERE id", static function () use ($import): bool {
    try {
        $import();
    } catch (ImportStopped $e) {
        return true;
    }
    return false;
});
test_ok('a failed read of the source text before saving stops the import, not "ignored" (Codex #7)', $stopped && $tr('ZQTRPIT context read') === null);

// ── 6. Languages ─────────────────────────────────────────────────────────────

test_section('languages (README §3, step 2):');

// WordPress's catalog may not be cached on this site, and refreshing it calls
// WordPress.org. Seed a minimal cached copy inside the transaction instead,
// and drop the catalog's per-request memo so it reads that copy.
if (get_site_transient('available_translations') === false) {
    set_site_transient('available_translations', [
        $LANG => ['language' => $LANG, 'english_name' => 'French (France)', 'native_name' => 'Français', 'iso' => ['fr']],
    ]);
}
$memo = new \ReflectionProperty(\LingoWP\Language\Infrastructure\WordPressLanguageCatalog::class, 'cache');
$memo->setAccessible(true);
$memo->setValue(null, null);

if (\LingoWP\Language\Infrastructure\WordPressLanguageCatalog::get($LANG) === null) {
    test_ok('WordPress\'s language catalog knows fr_FR', false);
} else {
    $optionNames = [LanguageMetadataStore::OPTION, OptionLanguageRegistry::OPTION_ACTIVE, OptionLanguageRegistry::OPTION_EXISTING];
    $snapshot    = [];
    foreach ($optionNames as $name) {
        $snapshot[$name] = get_option($name);
    }

    /** Back to the site's languages, then without fr_FR in all three options. */
    $withoutFr = static function () use ($optionNames, $snapshot, $LANG): void {
        foreach ($optionNames as $name) {
            update_option($name, $snapshot[$name]);
        }
        $map = (array) get_option(LanguageMetadataStore::OPTION, []);
        unset($map[$LANG]);
        update_option(LanguageMetadataStore::OPTION, $map);
        foreach ([OptionLanguageRegistry::OPTION_ACTIVE, OptionLanguageRegistry::OPTION_EXISTING] as $name) {
            $codes = array_filter(explode('|', (string) get_option($name, '')), static function (string $c) use ($LANG): bool {
                return $c !== '' && $c !== $LANG;
            });
            update_option($name, implode('|', $codes));
        }
    };
    $restore = static function () use ($optionNames, $snapshot): void {
        foreach ($optionNames as $name) {
            update_option($name, $snapshot[$name]);
        }
    };

    $settings = [
        'default-language'      => 'en_US',
        'translation-languages' => ['en_US', $LANG],
        'publish-languages'     => ['en_US', $LANG],
        'url-slugs'             => ['en_US' => 'en', $LANG => 'zqtrpit'],
    ];
    $store    = new LanguageMetadataStore();
    $registry = new OptionLanguageRegistry();

    $withoutFr();
    update_option('rewrite_rules', ['zq' => 'trpit']);
    $result = (new LanguageImport(false))->import($LANG, $settings);
    test_ok('a missing published language is added', $result['state'] === 'added' && $store->has($LANG));
    test_ok('it gets TranslatePress\'s slug', ($store->get($LANG)['slug'] ?? '') === 'zqtrpit');
    test_ok('it is active', in_array($LANG, $registry->getTargetLanguages(), true));
    test_ok('activating it clears the rewrite rules', get_option('rewrite_rules') === false);

    $withoutFr();
    $result = (new LanguageImport(false))->import($LANG, ['publish-languages' => ['en_US']] + $settings);
    test_ok('a missing unpublished language is added as a draft', $result['state'] === 'added' && !in_array($LANG, $registry->getTargetLanguages(), true));

    // Give the TranslatePress slug to some other LingoWP language, or to a
    // throwaway one when fr_FR is the only language on the site.
    $withoutFr();
    $map   = (array) get_option(LanguageMetadataStore::OPTION, []);
    $other = array_key_first($map) ?? 'zz_TRPIT';
    $map[$other] = ['slug' => 'zqtrpit'] + ($map[$other] ?? ['code' => $other]);
    update_option(LanguageMetadataStore::OPTION, $map);
    (new LanguageImport(false))->import($LANG, $settings);
    test_ok('a taken TranslatePress slug falls back to LingoWP\'s default', ($store->get($LANG)['slug'] ?? '') === 'fr-fr');

    $restore();
    $before = $store->get($LANG);
    $result = (new LanguageImport(false))->import($LANG, $settings);
    test_ok('an existing language is used as it is', $result['state'] === 'existing' && $store->get($LANG) === $before);

    $withoutFr();
    $result = (new LanguageImport(true))->import($LANG, $settings);
    test_ok('a dry run creates no language', $result['state'] === 'would_add' && !$store->has($LANG));

    // A language added but not activated is in the report (Codex 1st pass #8).
    $withoutFr();
    $realTrp = (array) get_option('trp_settings');
    update_option('trp_settings', array_merge($realTrp, $settings));
    $halfway = new Importer();
    $stoppedAtActivation = $withFailingQuery("`option_name` = 'lingowp_active_lang'", static function () use ($halfway, $wpdb, $LANG): bool {
        try {
            $halfway->run($wpdb, false, false, $LANG);
        } catch (ImportStopped $e) {
            return strpos($e->getMessage(), 'could not be activated') !== false;
        }
        return false;
    });
    $halfLanguage = $halfway->report()['languages'][$LANG]['language'] ?? [];
    test_ok('activation fails → the run stops, and the report still lists the added language', $stoppedAtActivation
        && ($halfLanguage['state'] ?? '') === 'added_inactive' && $store->has($LANG));
    update_option('trp_settings', $realTrp);

    $restore();
    $result = (new LanguageImport(false))->import('zz_ZZ', ['url-slugs' => []] + $settings);
    test_ok('a language unknown to WordPress is skipped as unsupported', $result['state'] === 'unsupported' && !$store->has('zz_ZZ'));

    if (!wp_using_ext_object_cache()) {
        $withoutFr();
        $timeoutBefore = get_site_option('_site_transient_timeout_available_translations', null);
        update_site_option('_site_transient_timeout_available_translations', time() - 60);
        $memo->setValue(null, null);
        $result = (new LanguageImport(true))->import($LANG, $settings);
        test_ok('a dry run with an expired catalog cache reports "unchecked" and deletes nothing (Codex #12)', $result['state'] === 'unchecked'
            && get_site_option('_site_transient_available_translations', false) !== false);
        // Back to a fresh cache, so no later case refreshes it from WordPress.org.
        if ($timeoutBefore === null) {
            delete_site_option('_site_transient_timeout_available_translations'); // was absent: never expires
        } else {
            update_site_option('_site_transient_timeout_available_translations', $timeoutBefore);
        }
        $memo->setValue(null, null);
    }

    $restore();
}

// ── 7. Gettext ───────────────────────────────────────────────────────────────

test_section('gettext (README §3, step 4):');

// LingoWP leaves its own text domain out of discovery; use the active theme's.
$components = (new InstalledGettextComponentDiscovery())->all();
$DOMAIN     = (string) wp_get_theme()->get('TextDomain');
$MOFILE     = '/themes/' . $DOMAIN . '-' . $LANG . '.mo';

if (!DictionaryImport::tableExists($wpdb, $GT) || !DictionaryImport::tableExists($wpdb, $GO) || ($components[$DOMAIN]['kind'] ?? '') !== 'theme') {
    test_ok('TranslatePress fr_FR gettext tables and the active theme\'s text domain exist', false);
} else {
    if (!class_exists('MO')) {
        require_once ABSPATH . WPINC . '/pomo/mo.php';
    }

    $roots = [];
    register_shutdown_function(static function () use (&$roots): void {
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); // phpcs:ignore
            }
            @rmdir($root); // phpcs:ignore
        }
    });
    $newRoot = static function () use (&$roots): string {
        $root    = sys_get_temp_dir() . '/lingowp-trpit-' . wp_generate_password(8, false, false);
        $roots[] = $root;
        return $root;
    };

    $wpdb->query("DELETE FROM {$GT}"); // phpcs:ignore WordPress.DB
    $stored = (array) get_option('trp_db_stored_data', []);
    $stored['gettext_plural_forms_header'][$LANG] = 'nplurals=2; plural=n > 1;';
    update_option('trp_db_stored_data', $stored);

    $gettext = static function (string $original, array $forms, ?string $domain = null, int $status = 1, string $plural = '') use ($GO, $GT, $DOMAIN): void {
        $domain = $domain ?? $DOMAIN;
        $originalId = test_insert($GO, [
            'original' => $original, 'domain' => $domain, 'context' => 'trp_context',
            'original_plural' => $plural, 'lookup_hash' => md5(uniqid('zqtrpit', true)),
        ]);
        foreach ($forms as $form => $translated) {
            test_insert($GT, [
                'original' => $original, 'translated' => $translated, 'domain' => $domain,
                'status' => $status, 'original_id' => $originalId, 'plural_form' => $form,
            ]);
        }
    };
    $runGettext = static function (string $root, bool $dryRun = false, bool $overwrite = false) use ($wpdb, $LANG): GettextImport {
        $run = new GettextImport($wpdb, $dryRun, $overwrite, $root);
        $run->run($LANG, $LANG);
        return $run;
    };
    $read = static function (string $root) use ($MOFILE): \MO {
        $mo = new \MO();
        $mo->import_from_file($root . $MOFILE);
        return $mo;
    };

    $gettext('ZQTRPIT singular', [0 => 'ZQ singulier']);
    $gettext('ZQTRPIT %d item', [1 => 'ZQ %d éléments', 0 => 'ZQ %d élément'], null, 2, 'ZQTRPIT %d items');
    $gettext('ZQTRPIT %d box', [0 => 'ZQ %d boîte'], null, 1, 'ZQTRPIT %d boxes');
    $gettext('ZQTRPIT copy', [0 => 'ZQ copie'], null, 4);
    $gettext('ZQTRPIT Hello %s', [0 => 'ZQ Bonjour']);
    $gettext('ZQTRPIT ghost', [0 => 'ZQ fantôme'], 'zqtrpit-not-installed');
    $gettext('ZQTRPIT kept', [0 => 'ZQ nouveau']);
    // An installed plugin with no French language pack anywhere WordPress
    // looks: the "no plural rule anywhere" case. Found, not hardcoded.
    global $wp_textdomain_registry;
    $NOPACK = '';
    foreach ($components as $candidate => $info) {
        $base = WP_LANG_DIR . '/plugins/' . $candidate . '-' . $LANG;
        if ($info['kind'] === 'plugin' && !is_readable($base . '.mo') && !is_readable($base . '.l10n.php')
            && !$wp_textdomain_registry->get((string) $candidate, $LANG)
            && !is_readable(rtrim((string) $info['path'], '/') . '/languages/' . $candidate . '-' . $LANG . '.mo')) {
            $NOPACK = (string) $candidate;
            break;
        }
    }
    if ($NOPACK !== '') {
        $gettext('ZQTRPIT nopack single', [0 => 'ZQ sans pack'], $NOPACK);
        $gettext('ZQTRPIT nopack %d row', [0 => 'ZQ %d ligne', 1 => 'ZQ %d lignes'], $NOPACK, 1, 'ZQTRPIT nopack %d rows');
    }

    // A string the theme's own French pack already translates, if it has one:
    // TranslatePress's translation must replace the pack's (Codex 2nd pass #4).
    $themePack = null;
    foreach ([WP_LANG_DIR . '/themes/' . $DOMAIN . '-' . $LANG . '.mo'] as $candidate) {
        if (is_readable($candidate)) {
            $themePack = new \MO();
            $themePack->import_from_file($candidate);
        }
    }
    $packKey = null;
    foreach ($themePack ? $themePack->entries : [] as $key => $entry) {
        if (!$entry->is_plural && !$entry->context && (string) ($entry->translations[0] ?? '') !== '') {
            $packKey = (string) $key;
            break;
        }
    }
    if ($packKey !== null) {
        $gettext($packKey, [0 => 'ZQ remplacé par TranslatePress']);
    }

    $root = $newRoot();
    $mo   = new \MO();
    $mo->add_entry(new \Translation_Entry(['singular' => 'ZQTRPIT kept', 'translations' => ['ZQ ancien']]));
    wp_mkdir_p(dirname($root . $MOFILE));
    $mo->export_to_file($root . $MOFILE);

    $run    = $runGettext($root);
    $mo     = $read($root);
    $counts = $run->counts();

    test_ok('a singular string is imported', ($mo->entries['ZQTRPIT singular']->translations[0] ?? null) === 'ZQ singulier');
    test_ok('a complete plural is imported with its forms in order', ($mo->entries['ZQTRPIT %d item']->translations ?? null) === ['ZQ %d élément', 'ZQ %d éléments']);
    test_ok('a plural with a missing form is skipped (D6)', !isset($mo->entries['ZQTRPIT %d box']) && $counts['incomplete_plural'] === 1);
    test_ok('status 3/4 rows are skipped', !isset($mo->entries['ZQTRPIT copy']));
    test_ok('a placeholder mismatch is skipped', !isset($mo->entries['ZQTRPIT Hello %s']) && $counts['placeholder_mismatch'] === 1);
    test_ok('a domain that is not an installed plugin or theme is reported', ($counts['not_installed']['zqtrpit-not-installed'] ?? 0) === 1);
    test_ok('an entry already in the file is kept', ($mo->entries['ZQTRPIT kept']->translations[0] ?? null) === 'ZQ ancien' && $counts['already'] === 1);
    test_ok('the file gets TranslatePress\'s plural rule', ($mo->headers['Plural-Forms'] ?? '') === 'nplurals=2; plural=n > 1;');
    test_ok('the domain is marked imported for the board', ((new GettextDomainRegistry())->get('theme', $DOMAIN, $LANG)['status'] ?? null) === GettextDomainRegistry::STATUS_IMPORTED);

    $runGettext($root, false, true);
    test_ok('--overwrite replaces the existing entry', ($read($root)->entries['ZQTRPIT kept']->translations[0] ?? null) === 'ZQ nouveau');

    $dryRoot = $newRoot();
    $run     = $runGettext($dryRoot, true);
    test_ok('a dry run writes no file', !file_exists($dryRoot . $MOFILE) && $run->counts()['imported'] > 0);

    // Codex review #1: LingoWP's own file replaces the language pack, so a new
    // file must start from the pack, or every other string loses its translation.
    $seedRoot = $newRoot();
    $runGettext($seedRoot);
    $seeded = $read($seedRoot);
    if ($themePack !== null) {
        $otherKey = null;
        foreach ($themePack->entries as $key => $entry) {
            if ($key !== $packKey) {
                $otherKey = (string) $key;
                break;
            }
        }
        test_ok('a new file starts from the installed language pack', $otherKey !== null && isset($seeded->entries[$otherKey]) && isset($seeded->entries['ZQTRPIT singular']));
        test_ok('…and keeps the pack\'s plural rule', ($seeded->headers['Plural-Forms'] ?? '') === (string) ($themePack->headers['Plural-Forms'] ?? ''));
        test_ok('TranslatePress replaces a pack translation without --overwrite (it isn\'t LingoWP\'s own)', $packKey !== null && ($seeded->entries[$packKey]->translations[0] ?? '') === 'ZQ remplacé par TranslatePress');
    } else {
        test_ok('without a language pack, a new file holds only the imported entries', isset($seeded->entries['ZQTRPIT singular']));
    }

    // The .l10n.php pack format (WordPress 6.5+) keeps only the singular in the
    // key; a plural's forms sit in the value (Codex 2nd pass #3).
    $phpRoot = $newRoot();
    wp_mkdir_p($phpRoot . '/component/languages');
    file_put_contents($phpRoot . '/component/languages/zqtrpit-php-' . $LANG . '.l10n.php', '<?php return ' . var_export([
        'plural-forms' => 'nplurals=2; plural=n > 1;',
        'messages'     => ['ZQTRPIT %d apple' => "ZQ %d pomme\0ZQ %d pommes", 'ZQTRPIT pear' => 'ZQ poire'],
    ], true) . ';');
    $seedMethod = new \ReflectionMethod(GettextImport::class, 'seedFromLanguagePack');
    $seedMethod->setAccessible(true);
    $phpMo = new \MO();
    $seedMethod->invoke(null, $phpMo, 'plugin', 'zqtrpit-php', $phpRoot . '/component', $LANG);
    test_ok('a .l10n.php pack keeps its plural forms', ($phpMo->entries['ZQTRPIT %d apple']->translations ?? null) === ['ZQ %d pomme', 'ZQ %d pommes'] && $phpMo->entries['ZQTRPIT %d apple']->is_plural);
    test_ok('…and its singulars and plural rule', ($phpMo->entries['ZQTRPIT pear']->translations[0] ?? '') === 'ZQ poire' && ($phpMo->headers['Plural-Forms'] ?? '') === 'nplurals=2; plural=n > 1;');
    @unlink($phpRoot . '/component/languages/zqtrpit-php-' . $LANG . '.l10n.php'); // phpcs:ignore
    @rmdir($phpRoot . '/component/languages'); // phpcs:ignore
    @rmdir($phpRoot . '/component'); // phpcs:ignore

    // The read-back check compares every translation, not only the count
    // (Codex 2nd pass #5).
    $same = new \ReflectionMethod(GettextImport::class, 'sameContent');
    $same->setAccessible(true);
    $a = new \MO();
    $a->add_entry(new \Translation_Entry(['singular' => 'ZQTRPIT x', 'translations' => ['ZQ complet']]));
    $b = new \MO();
    $b->add_entry(new \Translation_Entry(['singular' => 'ZQTRPIT x', 'translations' => ['ZQ comp']]));
    test_ok('a file whose translations were cut short fails the read-back check', $same->invoke(null, $a, $a) === true && $same->invoke(null, $a, $b) === false);

    // Codex 3rd pass #1: ownership is checked right before the file is replaced.
    $lostRoot = $newRoot();
    $lostRun  = new GettextImport($wpdb, false, true, $lostRoot, static function (bool $now = false): void {
        if ($now) {
            throw new ImportStopped('lost ownership');
        }
    });
    $lostStopped = false;
    try {
        $lostRun->run($LANG, $LANG);
    } catch (ImportStopped $e) {
        $lostStopped = true;
    }
    test_ok('a run that lost ownership replaces no file, and leaves no staging file', $lostStopped && glob($lostRoot . '/*/*') === []);

    // The board's counters are checked after a write (Codex 1st pass #9).
    $countersRoot = $newRoot();
    // Start from a count the import won't produce, so a refused update shows.
    (new \LingoWP\GettextDomains\GettextUnitRepository($wpdb))->setTranslatedInFile('theme', $DOMAIN, $LANG, 0);
    $countersMessage = $withFailingQuery('lingowp_gettext_translated_counts', static function () use ($wpdb, $LANG, $countersRoot): string {
        try {
            (new GettextImport($wpdb, false, true, $countersRoot))->run($LANG, $LANG);
        } catch (ImportStopped $e) {
            return $e->getMessage();
        }
        return '';
    });
    test_ok('a board counter that can\'t be updated stops the run with a clear message', strpos($countersMessage, 'counters could not be updated') !== false, $countersMessage);

    // Codex 3rd pass #3: the declared "Domain Path" (WordPress registers it only for active plugins).
    $languageDirs = new \ReflectionMethod(GettextImport::class, 'declaredLanguageDirs');
    $languageDirs->setAccessible(true);
    if (isset($components['woocommerce'])) {
        test_ok('a plugin\'s declared Domain Path is resolved to its folder (WooCommerce: i18n/languages)',
            in_array(WP_PLUGIN_DIR . '/woocommerce/i18n/languages', (array) $languageDirs->invoke(null, 'plugin', 'woocommerce'), true));
    }

    unset($stored['gettext_plural_forms_header'][$LANG]);
    update_option('trp_db_stored_data', $stored);
    if ($NOPACK === '') {
        test_ok('no installed plugin without a French pack: the no-plural-rule case is skipped', true);
    } else {
        $bareRoot = $newRoot();
        $run      = $runGettext($bareRoot);
        $bare     = new \MO();
        $bare->import_from_file($bareRoot . '/plugins/' . $NOPACK . '-' . $LANG . '.mo');
        test_ok('without any plural rule (no pack, no TranslatePress header), plurals are skipped and reported', !isset($bare->entries['ZQTRPIT nopack %d row']) && $run->counts()['no_plural_header'] >= 1);
        test_ok('singulars are still imported without a plural rule', isset($bare->entries['ZQTRPIT nopack single']));
    }
}

// ── 8. Checks before the run (last: it empties LingoWP's source tables) ──────

test_section('Importer — one flow for the command and the admin screen:');

$resetTrp();
$unit('ZQTRPIT via importer');
$trp('ZQTRPIT via importer', 'ZQ via l’importateur');
$report = (new Importer())->run($wpdb, true, false, $LANG);
$content = $report['languages'][$LANG]['content'] ?? null;
test_ok('run() returns the report as data, per TranslatePress language', is_array($content) && $content['imported'] === 1 && isset($report['languages'][$LANG]['language']['state']));
test_ok('the report carries the samples the screen lists', array_key_exists('unmatched_samples', $content ?? []));
test_ok('a dry run through Importer writes nothing', $tr('ZQTRPIT via importer') === null);

// Fixtures first: the failing-query filter would also block their own INSERT.
$resetTrp();
$unit('ZQTRPIT importer fail');
$trp('ZQTRPIT importer fail', 'ZQTRPIT-FAILWRITE');
$stoppedImporter = new Importer();
$partial = $withFailingQuery('ZQTRPIT-FAILWRITE', static function () use ($stoppedImporter, $wpdb): bool {
    try {
        $stoppedImporter->run($wpdb, false, false, 'fr_FR');
    } catch (ImportStopped $e) {
        return true;
    }
    return false;
});
test_ok('a stopped run still reports what it had done', $partial && isset($stoppedImporter->report()['languages']['fr_FR']['content']['imported']));

// ── 9. Admin migration: state, job, REST routes, boot flag ──────────────────

// The job and the command run the whole import, gettext included, into
// LingoWP's LIVE .mo folder — files the rollback can't undo. No gettext
// fixtures from here on, so there is nothing for them to write…
$wpdb->query("DELETE FROM {$GT}"); // phpcs:ignore WordPress.DB
// …and only fr_FR as a TranslatePress target, so no other language's gettext
// table can be imported either.
$onlyFr = (array) get_option('trp_settings');
$onlyFr['translation-languages'] = [$onlyFr['default-language'], $LANG];
update_option('trp_settings', $onlyFr);

test_section('admin migration — REST routes, background job, boot flag (PLAN §6):');

$asAdmin = static function () {
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    wp_set_current_user((int) ($admins[0] ?? 0));
};
$rest = static function (string $method, string $path): \WP_REST_Response {
    return rest_do_request(new \WP_REST_Request($method, '/lingowp/v1/migration/translatepress' . $path));
};

wp_set_current_user(0);
test_ok('the migration routes refuse visitors', in_array($rest('GET', '')->get_status(), [401, 403], true));
test_ok('…including "Migrate now"', in_array($rest('POST', '/run')->get_status(), [401, 403], true));

$asAdmin();
delete_option(MigrationState::OPTION);
$status = $rest('GET', '')->get_data();
test_ok('a never-run migration reports idle, with TranslatePress installed and inactive', ($status['state'] ?? '') === 'idle'
    && ($status['source']['installed'] ?? false) === true && ($status['source']['active'] ?? true) === false);

$flag = apply_filters('lingowp_admin_migration', null);
test_ok('the boot flag is set when TranslatePress is installed, not migrated yet', is_array($flag) && $flag['source'] === 'translatepress' && $flag['migrated'] === false);

$resetTrp();
$unit('ZQTRPIT admin migration');
$trp('ZQTRPIT admin migration', 'ZQ migration admin');
delete_transient(MigrationRestController::PREVIEW_TRANSIENT);
$preview = $rest('GET', '/preview')->get_data();
test_ok('the preview counts what can be imported, per language', ($preview['summary']['totals']['imported'] ?? 0) >= 1 && ($preview['summary']['languages'][0]['locale'] ?? '') === $LANG);
test_ok('the preview writes nothing', $tr('ZQTRPIT admin migration') === null);

$first = $rest('POST', '/run');
test_ok('"Migrate now" queues the background job', $first->get_status() === 200 && ($first->get_data()['state'] ?? '') === 'queued'
    && (bool) as_next_scheduled_action(MigrationJob::HOOK, ['run' => $first->get_data()['run'] ?? MigrationState::get()['run']], MigrationJob::GROUP));
$second = $rest('POST', '/run');
test_ok('a second click while queued is refused', $second->get_status() === 409);

MigrationJob::run(MigrationState::get()['run']);
$status = $rest('GET', '')->get_data();
test_ok('the job imports and stores done + report', ($status['state'] ?? '') === 'done' && ($status['summary']['totals']['imported'] ?? 0) >= 1
    && ($tr('ZQTRPIT admin migration')['translated_text'] ?? null) === 'ZQ migration admin');
test_ok('the screen always keeps existing translations (no overwrite)', ($tr('ZQTRPIT kept')['translated_text'] ?? null) === 'ZQ nouveau');
test_ok('the boot flag says migrated once done', (apply_filters('lingowp_admin_migration', null)['migrated'] ?? false) === true);
test_ok('the job drops the cached preview', get_transient(MigrationRestController::PREVIEW_TRANSIENT) === false);

$resetTrp();
$unit('ZQTRPIT admin fail');
$trp('ZQTRPIT admin fail', 'ZQTRPIT-FAILWRITE');
MigrationJob::queue();
$withFailingQuery('ZQTRPIT-FAILWRITE', static function (): void {
    MigrationJob::run(MigrationState::get()['run']);
});
$status = MigrationState::get();
test_ok('a failed job stores failed + the error', $status['state'] === 'failed' && $status['error'] !== '');

// Codex review #2: ownership.
delete_option(MigrationState::LOCK_OPTION);
update_option(MigrationState::OPTION, ['state' => 'running', 'run' => 'zq-dead-run', 'updated_at' => time()]);
test_ok('a running state whose owner is gone is reported as failed, so it can be retried', MigrationState::get()['state'] === 'failed' && !MigrationState::isBusy());

$owner = MigrationState::claim();
test_ok('one run claims the migration', is_string($owner) && MigrationState::owner() === $owner);
test_ok('a second claim is refused while the first is alive', MigrationState::claim() === null);
test_ok('"Migrate now" is refused while another run owns it', $rest('POST', '/run')->get_status() === 409);
$wpdb->update($wpdb->options, ['option_value' => $owner . '|' . (time() - 2 * HOUR_IN_SECONDS)], ['option_name' => MigrationState::LOCK_OPTION]);
$takeover = MigrationState::claim();
test_ok('a dead run\'s lock (no heartbeat) can be taken over', is_string($takeover) && $takeover !== $owner);
test_ok('a second takeover of the same dead lock is refused (conditional update)', MigrationState::claim() === null);
MigrationState::set('queued', $takeover);
MigrationJob::run($owner);
test_ok('a superseded job does nothing', MigrationState::owner() === $takeover && MigrationState::get()['run'] === $takeover && MigrationState::get()['state'] === 'queued');

$lost = false;
try {
    $beatClock = new \ReflectionProperty(MigrationState::class, 'lastBeat');
    $beatClock->setAccessible(true);
    $beatClock->setValue(null, []);
    MigrationState::beat($owner);
} catch (ImportStopped $e) {
    $lost = true;
}
test_ok('a run that lost ownership stops at its next heartbeat', $lost);
test_ok('…and can\'t record a state over the new owner', (static function () use ($owner): bool {
    try {
        MigrationState::set('done', $owner);
    } catch (ImportStopped $e) {
        return MigrationState::get()['run'] !== $owner;
    }
    return false;
})());
MigrationState::release($owner);
test_ok('releasing someone else\'s run removes nothing', MigrationState::owner() === $takeover);
MigrationState::release($takeover);
test_ok('releasing frees the migration', MigrationState::owner() === '');

$wpdb->insert($wpdb->options, ['option_name' => MigrationState::LOCK_OPTION, 'option_value' => 'garbage', 'autoload' => 'no']);
$afterGarbage = MigrationState::claim();
test_ok('a malformed lock row counts as stale, never blocks forever', is_string($afterGarbage));
MigrationState::release($afterGarbage);
$blocker = MigrationState::claim();
test_ok('the command refuses a real run while another run owns the migration', (static function (): bool {
    try {
        \LingoWPTranslatePress\ImportCommand::claim(false);
    } catch (ImportStopped $e) {
        return strpos($e->getMessage(), 'already running') !== false;
    }
    return false;
})());
test_ok('…but a dry run needs no ownership', \LingoWPTranslatePress\ImportCommand::claim(true) === '');
MigrationState::release($blocker);

$active = (array) get_option('active_plugins', []);
update_option('active_plugins', array_merge($active, [\LingoWPTranslatePress\Importer::TRANSLATEPRESS_PLUGIN]));
$deactivate = $rest('POST', '/deactivate');
test_ok('"Disable TranslatePress" deactivates it', $deactivate->get_status() === 200
    && !in_array(\LingoWPTranslatePress\Importer::TRANSLATEPRESS_PLUGIN, (array) get_option('active_plugins', []), true));
test_ok('…and keeps its data', DictionaryImport::tableExists($wpdb, $DICT) && is_array(get_option('trp_settings')));

delete_option(MigrationState::OPTION);
ob_start();
(new \LingoWPTranslatePress\ImportCommand())->__invoke([], ['dry-run' => true]);
ob_end_clean();
test_ok('a dry run from the command line doesn\'t count as migrated', !MigrationState::isDone());
set_transient(MigrationRestController::PREVIEW_TRANSIENT, ['ok' => true, 'blocked' => 'ZQ stale', 'summary' => null], 600);
ob_start();
(new \LingoWPTranslatePress\ImportCommand())->__invoke([], []);
ob_end_clean();
test_ok('a finished command-line run counts as migrated (the toast stops)', MigrationState::isDone());
test_ok('…and drops the screen\'s cached preview, so an old warning can\'t hide "Done" (Codex 5th pass #6)', get_transient(MigrationRestController::PREVIEW_TRANSIENT) === false);

$matcher = new AddonSiteMatcher();
test_ok('core: a migration add-on is relevant when its plugin is installed but inactive', $matcher->isPresent([\LingoWPTranslatePress\Importer::TRANSLATEPRESS_PLUGIN])
    && !$matcher->isRelevant([\LingoWPTranslatePress\Importer::TRANSLATEPRESS_PLUGIN]));
test_ok('core: a plugin that is not on the site is not present', !$matcher->isPresent(['zq-trpit-missing/zq.php']));
wp_set_current_user(0);

test_section('checks before anything is written (README §3, step 1):');

$command  = new Importer();
$expectStop = static function (callable $fn): bool {
    try {
        $fn();
    } catch (ImportStopped $e) {
        return true;
    }
    return false;
};
$settings = (array) get_option('trp_settings');

test_ok('--lang accepts a TranslatePress language in any spelling', $command->targets($settings, 'fr-FR') === [$LANG]);
test_ok('--lang with an unknown language stops', $expectStop(static function () use ($command, $settings) {
    $command->targets($settings, 'de_DE');
}));

update_option('trp_settings', ['default-language' => 'de_DE'] + $settings);
test_ok('a TranslatePress default language other than LingoWP\'s source stops', $expectStop(static function () use ($command, $wpdb) {
    $command->checks($wpdb);
}));

delete_option('trp_settings');
test_ok('missing TranslatePress settings stop', $expectStop(static function () use ($command, $wpdb) {
    $command->checks($wpdb);
}));

update_option('trp_settings', $settings);
foreach (TranslationMemorySchema::parentTables() as $table) {
    $wpdb->query("DELETE FROM {$table}"); // phpcs:ignore WordPress.DB
}
test_ok('a site LingoWP never scanned stops', $expectStop(static function () use ($command, $wpdb) {
    $command->checks($wpdb);
}));

test_section('isolation:');
test_ok('the live .mo folder is unchanged (files are outside the rollback)', $liveMoFiles() === $liveMoBefore);

test_done();

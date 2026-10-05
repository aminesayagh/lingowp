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

use LingoWP\Board\CollectionBoard;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\GettextDomains\GettextDomainRegistry;
use LingoWP\GettextDomains\InstalledGettextComponentDiscovery;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Shared\Text\TranslationKey;
use LingoWPTranslatePress\DictionaryImport;
use LingoWPTranslatePress\GettextImport;
use LingoWPTranslatePress\ImportCommand;
use LingoWPTranslatePress\ImportStopped;
use LingoWPTranslatePress\LanguageImport;

global $wpdb;

test_guard();

if (!class_exists(DictionaryImport::class)) {
    fwrite(STDERR, "SKIPPED: lingowp-translatepress is not active; this suite needs it.\n");
    exit(0);
}

$LANG = 'fr_FR';
$DICT = ImportCommand::dictionaryTable($wpdb, 'en_US', $LANG);
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
$touched = [$DICT, $GT, $GO, $HP, $PP, $MP, $HP . '_translation', $PP . '_translation', $MP . '_translation', $wpdb->posts, $wpdb->options];
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

    $restore();
    $result = (new LanguageImport(false))->import('zz_ZZ', ['url-slugs' => []] + $settings);
    test_ok('a language unknown to WordPress is skipped as unsupported', $result['state'] === 'unsupported' && !$store->has('zz_ZZ'));

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
            foreach (glob($root . '/*/*') ?: [] as $file) {
                @unlink($file); // phpcs:ignore
            }
            foreach (glob($root . '/*') ?: [] as $dir) {
                @rmdir($dir); // phpcs:ignore
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

    unset($stored['gettext_plural_forms_header'][$LANG]);
    update_option('trp_db_stored_data', $stored);
    $bareRoot = $newRoot();
    $run      = $runGettext($bareRoot);
    test_ok('without any plural rule, plurals are skipped and reported', !isset($read($bareRoot)->entries['ZQTRPIT %d item']) && $run->counts()['no_plural_header'] >= 1);
    test_ok('singulars are still imported without a plural rule', isset($read($bareRoot)->entries['ZQTRPIT singular']));
}

// ── 8. Checks before the run (last: it empties LingoWP's source tables) ──────

test_section('checks before anything is written (README §3, step 1):');

$command  = new ImportCommand();
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

test_done();

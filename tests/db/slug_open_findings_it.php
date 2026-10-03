<?php

/**
 * REPRODUCTION SUITE for the five findings left open after the route-position
 * redesign (second cross-review numbering). Every assertion states the DESIRED
 * behavior, so a FAIL means "bug reproduced", and this file is expected to be
 * red until the fixes land.
 *
 * Deliberately NOT registered in composer.json's test:db yet: it would turn the
 * suite red. Register it in the same change that fixes these, at which point it
 * must go all-green — same contract as tests/db/slug_routing_bugs_it.php had.
 *
 * One section per finding, independent of each other on purpose: each seeds its
 * own fixtures and cleans up any $_SERVER it touches, so a section can be run,
 * read, or fixed without untangling it from the others.
 *
 *   O7   REQUEST_URI and PATH_INFO can be spelled differently, and only one
 *        gets normalized — WordPress then routes the other one.
 *   O9a  an old-slug alias written straight to post meta never invalidates.
 *   O9c  restoreSources() makes a title translatable again without invalidating.
 *   O11  a SINGLE stale source row is never checked against the current title.
 *   O12  the language-INDEPENDENT reserved set is re-queried per language.
 *   O14  LanguageLinks caches its own rows, unaware of the slug-map version.
 *
 * Run: wp eval-file tests/db/slug_open_findings_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\Repository\WpDbBoardQueries;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Language\Infrastructure\SupportedLanguages;
use LingoWP\LocalizationRouting\LanguageLinks;
use LingoWP\LocalizationRouting\LanguageRouteRegistrar;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;
use LingoWP\LocalizationRouting\SlugMap;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Text\TranslationKey;

test_guard();
test_begin();

global $wpdb;

$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();

$store->save(['code' => 'zz_ZZ', 'slug' => 'zz']);
$registry->enableLanguage('zz_ZZ');

$default = $registry->getDefaultLanguage();
update_option(SlugMap::OPTION_ENABLED, true);

$parentTable = TranslationMemorySchema::parentTables()['post'];
$childTable  = $parentTable . '_translation';

/**
 * Seed a page, and optionally a post_title source row plus its translation.
 * $sourceText defaults to the page title; pass it explicitly to seed a row
 * whose stored source text does NOT match the current title (a stale row).
 *
 * @return array{0:int,1:int} [post id, parent row id]
 */
function test_seed(
    string $slug,
    string $title,
    ?string $translation,
    ?string $sourceText = null,
    string $lang = 'zz_ZZ'
): array {
    global $wpdb;

    // Resolved here, not pulled from a top-level variable: wp eval-file runs
    // this script inside a function, so those are locals and `global` on them
    // yields null — which silently sends every INSERT to an empty table name.
    $parentTable = TranslationMemorySchema::parentTables()['post'];
    $childTable  = $parentTable . '_translation';

    $now = current_time('mysql');

    $postId = test_insert($wpdb->posts, [
        'post_author'       => 1,
        'post_date'         => $now,
        'post_date_gmt'     => $now,
        'post_content'      => '',
        'post_title'        => $title,
        'post_excerpt'      => '',
        'post_status'       => 'publish',
        'comment_status'    => 'closed',
        'ping_status'       => 'closed',
        'post_name'         => $slug,
        'post_modified'     => $now,
        'post_modified_gmt' => $now,
        'post_parent'       => 0,
        'guid'              => '',
        'menu_order'        => 0,
        'post_type'         => 'page',
        'comment_count'     => 0,
    ]);

    if ($translation === null) {
        return [$postId, 0];
    }

    $stored  = $sourceText ?? $title;
    $hashHex = bin2hex(TranslationKey::currentHash($stored));

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$parentTable} (post_id, field, original_hash, original_text, status, created_at, updated_at)
         VALUES (%d, 'post_title', UNHEX(%s), %s, 0, %s, %s)",
        $postId,
        $hashHex,
        $stored,
        $now,
        $now
    ));
    $parentRowId = (int) $wpdb->insert_id;

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$childTable} (original_hash, lang_code, translated_text, status, created_at, updated_at)
         VALUES (UNHEX(%s), %s, %s, 1, %s, %s)",
        $hashHex,
        $lang,
        $translation,
        $now,
        $now
    ));

    return [$postId, $parentRowId];
}

function test_map(OptionLanguageRegistry $registry): SlugMap
{
    global $wpdb;
    return new SlugMap($wpdb, $registry);
}

function test_builder(OptionLanguageRegistry $registry): LocalizedUrlBuilder
{
    return new LocalizedUrlBuilder(new ResolveRequestLanguage($registry), test_map($registry));
}

function test_version(): string
{
    return (string) get_option('lingowp_slug_map_version', '0');
}

test_ok('setup: zz_ZZ is published', $registry->isEnabledLanguage('zz_ZZ'));

// Regenerate so the language-prefixed clones exist, as on a real request.
global $wp_rewrite;
update_option('rewrite_rules', '');
$wp_rewrite->wp_rewrite_rules();

// ── O7: REQUEST_URI and PATH_INFO disagree ───────────────────────────────────
// Some servers expose PATH_INFO DECODED while REQUEST_URI stays percent-encoded.
// normalizeRequestPath() rewrites both by searching for the path it parsed out
// of REQUEST_URI, so the differently-spelled copy is missed — and core prefers
// the non-empty PATH_INFO, routing the slug that was never translated back.

test_section('O7: both copies of the request path must be normalized');

test_seed('lingowp-test-o7', 'Lingowp Test O7', 'عن');

$arabicSlug = sanitize_title('عن');
test_ok(
    'precondition: the derived slug is percent-encoded',
    str_contains($arabicSlug, '%'),
    $arabicSlug
);

$registrarO7 = new LanguageRouteRegistrar(new ResolveRequestLanguage($registry), test_map($registry));
SlugMap::bumpVersion();

$_SERVER['REQUEST_URI'] = '/zz/' . $arabicSlug . '/';
$_SERVER['PATH_INFO']   = '/zz/' . rawurldecode($arabicSlug) . '/'; // the decoded spelling

$registrarO7->normalizeRequestPath(true);
$seenUri      = (string) $_SERVER['REQUEST_URI'];
$seenPathInfo = (string) $_SERVER['PATH_INFO'];
$registrarO7->restoreRequestPath();

test_ok(
    'REQUEST_URI is normalized to the source slug',
    $seenUri === '/zz/lingowp-test-o7/',
    $seenUri
);
test_ok(
    'PATH_INFO is normalized too (core prefers it when set)',
    $seenPathInfo === '/zz/lingowp-test-o7/',
    $seenPathInfo
);

// PATH_INFO must not leak into the sections below: core would route on it.
unset($_SERVER['PATH_INFO']);
$_SERVER['REQUEST_URI'] = '/';

// ── O9a: an old-slug alias written straight to meta ──────────────────────────
// Renaming a post leaves a _wp_old_slug record so the old URL keeps working.
// An importer writing that record fires no post-save hook, so the cached map
// keeps handing the alias to whichever page derived it.

test_section('O9a: writing _wp_old_slug must invalidate the map');

[$aliasOwnerId] = test_seed('lingowp-test-o9a-owner', 'Lingowp Test O9a Owner', null);
test_seed('lingowp-test-o9a-claim', 'Lingowp Test O9a Claim', 'Lingowp Zed O9a Alias');
SlugMap::bumpVersion();

// Warm the map so the claimant owns the alias.
test_ok(
    'precondition: the claimant currently owns that slug',
    test_map($registry)->toSource('/lingowp-zed-o9a-alias/', 'zz_ZZ') === '/lingowp-test-o9a-claim/',
    test_map($registry)->toSource('/lingowp-zed-o9a-alias/', 'zz_ZZ')
);

$versionBefore = test_version();
add_post_meta($aliasOwnerId, '_wp_old_slug', 'lingowp-zed-o9a-alias');

test_ok(
    'adding the alias bumps the cache version',
    test_version() !== $versionBefore,
    'before=' . $versionBefore . ' after=' . test_version()
);
test_ok(
    'and the alias stops being handed to the other page',
    test_map($registry)->toSource('/lingowp-zed-o9a-alias/', 'zz_ZZ') === '/lingowp-zed-o9a-alias/',
    test_map($registry)->toSource('/lingowp-zed-o9a-alias/', 'zz_ZZ')
);

// ── O9c: restoring an ignored source ─────────────────────────────────────────
// Ignoring a title takes it out of the map's candidates and DOES invalidate.
// Restoring it puts it back, and does not.

test_section('O9c: restoreSources() must invalidate the map');

[, $restoreParentId] = test_seed('lingowp-test-o9c', 'Lingowp Test O9c', 'Lingowp Zed O9c');
$repo = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
$ref  = SourceRef::make('post', $restoreParentId);

SlugMap::bumpVersion();
$repo->ignoreSource($ref);

test_ok(
    'precondition: an ignored title produces no slug',
    test_builder($registry)->addPrefix('/lingowp-test-o9c/', 'zz_ZZ') === '/zz/lingowp-test-o9c/',
    test_builder($registry)->addPrefix('/lingowp-test-o9c/', 'zz_ZZ')
);

$versionBefore = test_version();
$repo->restoreSources([$ref]);

test_ok(
    'restoring bumps the cache version',
    test_version() !== $versionBefore,
    'before=' . $versionBefore . ' after=' . test_version()
);
// Ignoring DELETES the child translations by design ("untranslated until
// re-translated"), so a bare restore cannot bring a slug back — only the
// candidate row returns. Re-translate through the repository and the slug must
// appear, which is what proves the restored row is eligible again.
test_ok(
    'a restored title is eligible, so re-translating produces its slug',
    (static function () use ($repo, $ref, $registry): string {
        $repo->saveTranslation($ref, 'zz_ZZ', 'Lingowp Zed O9c', 1, null);
        return test_builder($registry)->addPrefix('/lingowp-test-o9c/', 'zz_ZZ');
    })() === '/zz/lingowp-zed-o9c/',
    test_builder($registry)->addPrefix('/lingowp-test-o9c/', 'zz_ZZ')
);

// ── O11: a single stale source row ───────────────────────────────────────────
// A title change normally deletes the old source row. After an interrupted
// reconcile one can survive alone, and preferCurrentSource() only compares rows
// when there are two or more — so a lone stale row is used unquestioned.

test_section('O11: a lone stale source row must not supply the slug');

// The page is titled "...New"; the only source row stored is "...Old".
test_seed('lingowp-test-o11', 'Lingowp Test O11 New', 'Lingowp Zed O11 Old', 'Lingowp Test O11 Old');
SlugMap::bumpVersion();

test_ok(
    'a slug derived from a stale title is not used',
    test_builder($registry)->addPrefix('/lingowp-test-o11/', 'zz_ZZ') === '/zz/lingowp-test-o11/',
    test_builder($registry)->addPrefix('/lingowp-test-o11/', 'zz_ZZ')
);

// ── O12: the reserved set is language-independent ────────────────────────────
// Building a map needs that language's translations (per-language) plus the set
// of slugs that already exist (identical for every language). The second is
// computed inside the per-language build, so N languages pay for it N times.

test_section('O12: the reserved set must be computed once per request');

$store->save(['code' => 'yy_YY', 'slug' => 'yy']);
$registry->enableLanguage('yy_YY');

$sharedMap = test_map($registry); // ONE instance, as a real request has
SlugMap::bumpVersion();

$before = $wpdb->num_queries;
$sharedMap->toSource('/lingowp-test-o11/', 'zz_ZZ');
$firstLanguageCost = $wpdb->num_queries - $before;

$before = $wpdb->num_queries;
$sharedMap->toSource('/lingowp-test-o11/', 'yy_YY');
$secondLanguageCost = $wpdb->num_queries - $before;

test_ok(
    'precondition: the first language really did build a map',
    $firstLanguageCost > 0,
    'queries=' . $firstLanguageCost
);
test_ok(
    'a second language costs less than the first (shared work is reused)',
    $secondLanguageCost < $firstLanguageCost,
    "first={$firstLanguageCost} second={$secondLanguageCost}"
);

// ── O14: LanguageLinks caches its own rows ───────────────────────────────────
// SlugMap's memo is keyed by the cache version, so it sees a mid-request change.
// LanguageLinks memoizes the finished rows without that key, so the switcher
// keeps serving URLs built before the change.

test_section('O14: the switcher must not serve pre-change URLs');

[$o14PostId] = test_seed('lingowp-test-o14', 'Lingowp Test O14', 'Lingowp Zed O14');
SlugMap::bumpVersion();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/lingowp-test-o14/';
set_query_var('lingowp_lang', '');

$links = new LanguageLinks(
    new ResolveRequestLanguage($registry),
    test_builder($registry),
    new SupportedLanguages($store)
);

/** @return string the zz_ZZ row's path */
function test_zz_path(LanguageLinks $links): string
{
    foreach ($links->links() as $row) {
        if ($row['code'] === 'zz_ZZ') {
            return (string) wp_parse_url((string) $row['url'], PHP_URL_PATH);
        }
    }

    return '(missing)';
}

test_ok(
    'precondition: the switcher shows the translated slug',
    test_zz_path($links) === '/zz/lingowp-zed-o14/',
    test_zz_path($links)
);

$wpdb->query($wpdb->prepare(
    "UPDATE {$childTable} c
        JOIN {$parentTable} p ON p.original_hash = c.original_hash
        SET c.translated_text = %s
      WHERE p.post_id = %d AND p.field = 'post_title' AND c.lang_code = %s",
    'Lingowp Zed O14 Changed',
    $o14PostId,
    'zz_ZZ'
));
SlugMap::bumpVersion();

test_ok(
    'the same instance reflects the new translation',
    test_zz_path($links) === '/zz/lingowp-zed-o14-changed/',
    test_zz_path($links)
);

set_query_var('lingowp_lang', '');
$_SERVER['REQUEST_URI'] = '/';

test_done();

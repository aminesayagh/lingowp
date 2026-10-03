<?php

/**
 * Translated URL path segments end to end, against the real classes and the
 * live database — no mocks for SlugMap / LocalizedUrlBuilder /
 * LanguageRouteRegistrar, since the thing being verified is exactly how their
 * queries, conflict rules and hooks behave together.
 *
 * Covers:
 *   1. Outgoing: addPrefix() mints a translated path; stripPrefix() undoes it.
 *   2. Incoming: the 'request' filter maps a translated slug back to the slug
 *      WordPress stores, so no new rewrite rule is needed.
 *   3. The source slug keeps working as a permanent alias.
 *   4. The published-language gate: an unpublished (draft) language and the
 *      default language never get a translated URL, and neither does anything
 *      while the feature option is off.
 *   5. The two dangerous conflicts, against real fixtures: a derived slug that
 *      collides with a real slug, and two titles sharing one translation.
 *   6. A needs-review translation still yields a slug.
 *
 * Run: wp eval-file tests/db/slug_translation_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\LocalizationRouting\LanguageRouteRegistrar;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;
use LingoWP\LocalizationRouting\SlugMap;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Shared\Text\TranslationKey;

test_guard();
test_begin();

// wp eval-file runs this in a function scope, so $wpdb has to be pulled in
// explicitly before any top-level use (same as reconcile_it.php).
global $wpdb;

// ── Setup ────────────────────────────────────────────────────────────────────
// zz_ZZ (published) and qq_QQ (metadata only, NEVER enabled — the draft case)
// are not real WordPress locales, so they cannot collide with this dev site.
// Every fixture slug and title is namespaced for the same reason.

$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();

$store->save(['code' => 'zz_ZZ', 'slug' => 'zz']);
$registry->enableLanguage('zz_ZZ');

$store->save(['code' => 'qq_QQ', 'slug' => 'qq']); // draft: saved, not enabled

$default = $registry->getDefaultLanguage();

test_ok('default language is set up', $default !== '', "default=$default");
test_ok('zz_ZZ is published', $registry->isEnabledLanguage('zz_ZZ'));
test_ok('qq_QQ is NOT published', !$registry->isEnabledLanguage('qq_QQ'));

update_option(SlugMap::OPTION_ENABLED, true);

/**
 * Raw fixture insert — never wp_insert_post(), which would fire discovery.
 * Seeds the page, and optionally the post_title source row plus its
 * translation, which is what SlugMap derives the slug from.
 */
function test_seed_page(string $slug, string $title, ?string $translation, string $lang, int $status = 1): int
{
    global $wpdb;

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
        return $postId;
    }

    $parent  = TranslationMemorySchema::parentTables()['post'];
    $child   = $parent . '_translation';
    $hashHex = bin2hex(TranslationKey::currentHash($title));

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$parent} (post_id, field, original_hash, original_text, status, created_at, updated_at)
         VALUES (%d, 'post_title', UNHEX(%s), %s, 0, %s, %s)",
        $postId,
        $hashHex,
        $title,
        $now,
        $now
    ));

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$child} (original_hash, lang_code, translated_text, status, created_at, updated_at)
         VALUES (UNHEX(%s), %s, %s, %d, %s, %s)",
        $hashHex,
        $lang,
        $translation,
        $status,
        $now,
        $now
    ));

    return $postId;
}

// Baseline pair.
test_seed_page('lingowp-test-about', 'Lingowp Test About', 'Lingowp Zed Apropos', 'zz_ZZ');
// Untranslated: must keep its source slug.
test_seed_page('lingowp-test-plain', 'Lingowp Test Plain', null, 'zz_ZZ');
// needs-review: must still yield a slug.
test_seed_page('lingowp-test-review', 'Lingowp Test Review', 'Lingowp Zed Revue', 'zz_ZZ', TranslationStatus::NEEDS_REVIEW);

// Conflict rule 3 — the derived slug is already a real page's slug.
test_seed_page('lingowp-zed-reserved', 'Lingowp Test Reserved Owner', null, 'zz_ZZ');
test_seed_page('lingowp-test-claimant', 'Lingowp Test Claimant', 'Lingowp Zed Reserved', 'zz_ZZ');

// Conflict rule 2 — two different titles sharing one translation.
$winnerId = test_seed_page('lingowp-test-first', 'Lingowp Test First', 'Lingowp Zed Shared', 'zz_ZZ');
$loserId  = test_seed_page('lingowp-test-second', 'Lingowp Test Second', 'Lingowp Zed Shared', 'zz_ZZ');

test_ok('rule 2 fixtures are ordered (winner has the lower id)', $winnerId < $loserId, "$winnerId < $loserId");

SlugMap::bumpVersion(); // fixtures landed after any earlier cached map

$slugMap   = new SlugMap($wpdb, $registry);
$resolver  = new ResolveRequestLanguage($registry);
$builder   = new LocalizedUrlBuilder($resolver, $slugMap);
$registrar = new LanguageRouteRegistrar($resolver, $slugMap);

// ── 1. Outgoing ──────────────────────────────────────────────────────────────

test_section('outgoing: addPrefix() mints a translated path');

test_ok(
    'translated slug replaces the source slug',
    $builder->addPrefix('/lingowp-test-about/', 'zz_ZZ') === '/zz/lingowp-zed-apropos/',
    $builder->addPrefix('/lingowp-test-about/', 'zz_ZZ')
);
test_ok(
    'trailing-slash shape is preserved',
    $builder->addPrefix('/lingowp-test-about', 'zz_ZZ') === '/zz/lingowp-zed-apropos',
    $builder->addPrefix('/lingowp-test-about', 'zz_ZZ')
);
test_ok(
    'an untranslated page keeps its source slug',
    $builder->addPrefix('/lingowp-test-plain/', 'zz_ZZ') === '/zz/lingowp-test-plain/',
    $builder->addPrefix('/lingowp-test-plain/', 'zz_ZZ')
);
test_ok(
    'a needs-review translation still yields a slug',
    $builder->addPrefix('/lingowp-test-review/', 'zz_ZZ') === '/zz/lingowp-zed-revue/',
    $builder->addPrefix('/lingowp-test-review/', 'zz_ZZ')
);

test_section('outgoing: stripPrefix() returns a source-language path');

test_ok(
    'stripPrefix undoes the slug translation',
    $builder->stripPrefix('/zz/lingowp-zed-apropos/') === '/lingowp-test-about/',
    $builder->stripPrefix('/zz/lingowp-zed-apropos/')
);
test_ok(
    'addPrefix(stripPrefix(x)) is idempotent',
    $builder->addPrefix($builder->stripPrefix('/zz/lingowp-zed-apropos/'), 'zz_ZZ') === '/zz/lingowp-zed-apropos/',
    $builder->addPrefix($builder->stripPrefix('/zz/lingowp-zed-apropos/'), 'zz_ZZ')
);
test_ok(
    'switching to the default language yields the source slug',
    $builder->addPrefix('/zz/lingowp-zed-apropos/', $default) === '/lingowp-test-about/',
    $builder->addPrefix('/zz/lingowp-zed-apropos/', $default)
);

// ── 2. Incoming ──────────────────────────────────────────────────────────────

test_section('incoming: the request PATH is normalized before rule matching');

/**
 * What normalizeRequestPath() leaves in $_SERVER['REQUEST_URI'] for a given
 * request — i.e. the path WordPress will match a rewrite rule against.
 *
 * Unit-level on purpose; tests/db/slug_routing_bugs_it.php drives the whole
 * pipeline (rule matching + WP_Query) for the cases where only real routing
 * can tell the truth.
 */
function test_normalized_path(LanguageRouteRegistrar $registrar, string $uri): string
{
    $_SERVER['REQUEST_URI'] = $uri;
    $registrar->normalizeRequestPath(true);
    $seen = (string) $_SERVER['REQUEST_URI'];
    $registrar->restoreRequestPath();

    return $seen;
}

test_ok(
    'a translated path becomes the source path',
    test_normalized_path($registrar, '/zz/lingowp-zed-apropos/') === '/zz/lingowp-test-about/',
    test_normalized_path($registrar, '/zz/lingowp-zed-apropos/')
);
test_ok(
    'every segment of a hierarchical path is mapped',
    test_normalized_path($registrar, '/zz/lingowp-zed-apropos/lingowp-zed-revue/') === '/zz/lingowp-test-about/lingowp-test-review/',
    test_normalized_path($registrar, '/zz/lingowp-zed-apropos/lingowp-zed-revue/')
);
test_ok(
    'the query string survives normalization',
    test_normalized_path($registrar, '/zz/lingowp-zed-apropos/?foo=bar') === '/zz/lingowp-test-about/?foo=bar',
    test_normalized_path($registrar, '/zz/lingowp-zed-apropos/?foo=bar')
);
test_ok(
    'the language prefix itself is never treated as a slug',
    test_normalized_path($registrar, '/zz/') === '/zz/',
    test_normalized_path($registrar, '/zz/')
);

test_section('incoming: the request URI is always restored');

$_SERVER['REQUEST_URI'] = '/zz/lingowp-zed-apropos/';
$registrar->normalizeRequestPath(true);
$registrar->restoreRequestPath();
test_ok(
    'downstream consumers see the URL the visitor asked for',
    $_SERVER['REQUEST_URI'] === '/zz/lingowp-zed-apropos/',
    (string) $_SERVER['REQUEST_URI']
);

$registrar->restoreRequestPath(); // idempotent: armed on both parse_request and wp
test_ok(
    'restoring twice is harmless',
    $_SERVER['REQUEST_URI'] === '/zz/lingowp-zed-apropos/',
    (string) $_SERVER['REQUEST_URI']
);

test_section('incoming: the source slug stays a permanent alias');

test_ok(
    'a source-slug path under the prefix is left untouched',
    test_normalized_path($registrar, '/zz/lingowp-test-about/') === '/zz/lingowp-test-about/',
    test_normalized_path($registrar, '/zz/lingowp-test-about/')
);
test_ok(
    'an unknown slug is left untouched (a 404 stays a 404)',
    test_normalized_path($registrar, '/zz/lingowp-test-unknown-xyz/') === '/zz/lingowp-test-unknown-xyz/',
    test_normalized_path($registrar, '/zz/lingowp-test-unknown-xyz/')
);
test_ok(
    'no language prefix means no normalization at all',
    test_normalized_path($registrar, '/lingowp-zed-apropos/') === '/lingowp-zed-apropos/',
    test_normalized_path($registrar, '/lingowp-zed-apropos/')
);

// ── 3. The published-language gate ───────────────────────────────────────────

test_section('gate: unpublished and default languages mint nothing');

test_ok(
    'a draft language gets no translated slug',
    $slugMap->localize('/lingowp-test-about/', 'qq_QQ') === '/lingowp-test-about/',
    $slugMap->localize('/lingowp-test-about/', 'qq_QQ')
);
test_ok(
    'a draft language gets no prefixed URL either (pre-existing rule)',
    $builder->addPrefix('/lingowp-test-about/', 'qq_QQ') === '/lingowp-test-about/',
    $builder->addPrefix('/lingowp-test-about/', 'qq_QQ')
);
test_ok(
    'the default language is never slug-mapped',
    $slugMap->localize('/lingowp-test-about/', $default) === '/lingowp-test-about/',
    $slugMap->localize('/lingowp-test-about/', $default)
);
test_ok(
    'an unknown language code maps nothing',
    $slugMap->localize('/lingowp-test-about/', 'xx_XX') === '/lingowp-test-about/',
    $slugMap->localize('/lingowp-test-about/', 'xx_XX')
);

// ── 4. Conflicts, against real rows ──────────────────────────────────────────

test_section('conflicts: rule 3 — derived slug collides with a real slug');

test_ok(
    'the claimant keeps its source slug',
    $builder->addPrefix('/lingowp-test-claimant/', 'zz_ZZ') === '/zz/lingowp-test-claimant/',
    $builder->addPrefix('/lingowp-test-claimant/', 'zz_ZZ')
);

test_ok(
    'the real owner of that slug still resolves to itself',
    test_normalized_path($registrar, '/zz/lingowp-zed-reserved/') === '/zz/lingowp-zed-reserved/',
    test_normalized_path($registrar, '/zz/lingowp-zed-reserved/')
);

test_section('conflicts: rule 2 — two titles share one translation');

$firstUrl  = $builder->addPrefix('/lingowp-test-first/', 'zz_ZZ');
$secondUrl = $builder->addPrefix('/lingowp-test-second/', 'zz_ZZ');

test_ok(
    'the lower-id page wins the shared slug',
    $firstUrl === '/zz/lingowp-zed-shared/',
    $firstUrl
);
test_ok(
    'the loser keeps its source slug instead of duplicating the winner',
    $secondUrl === '/zz/lingowp-test-second/',
    $secondUrl
);
test_ok(
    'the two pages never share a URL',
    $firstUrl !== $secondUrl,
    "$firstUrl vs $secondUrl"
);

test_ok(
    'the shared slug normalizes to the winner only',
    test_normalized_path($registrar, '/zz/lingowp-zed-shared/') === '/zz/lingowp-test-first/',
    test_normalized_path($registrar, '/zz/lingowp-zed-shared/')
);
test_ok(
    'the loser is still reachable at its source slug',
    test_normalized_path($registrar, '/zz/lingowp-test-second/') === '/zz/lingowp-test-second/',
    test_normalized_path($registrar, '/zz/lingowp-test-second/')
);

// ── 4b. Terms ────────────────────────────────────────────────────────────────

test_section('terms: a translated category slug');

$termId = test_insert($wpdb->terms, ['name' => 'Lingowp Test Topic', 'slug' => 'lingowp-test-topic', 'term_group' => 0]);
$ttId   = test_insert($wpdb->term_taxonomy, [
    'term_id'     => $termId,
    'taxonomy'    => 'category',
    'description' => '',
    'parent'      => 0,
    'count'       => 0,
]);

$termParent = TranslationMemorySchema::parentTables()['term'];
$termChild  = $termParent . '_translation';
$termHash   = bin2hex(TranslationKey::currentHash('Lingowp Test Topic'));
$now        = current_time('mysql');

$wpdb->query($wpdb->prepare(
    "INSERT INTO {$termParent} (term_taxonomy_id, field, original_hash, original_text, status, created_at, updated_at)
     VALUES (%d, 'name', UNHEX(%s), %s, 0, %s, %s)",
    $ttId,
    $termHash,
    'Lingowp Test Topic',
    $now,
    $now
));
$wpdb->query($wpdb->prepare(
    "INSERT INTO {$termChild} (original_hash, lang_code, translated_text, status, created_at, updated_at)
     VALUES (UNHEX(%s), %s, %s, 1, %s, %s)",
    $termHash,
    'zz_ZZ',
    'Lingowp Zed Sujet',
    $now,
    $now
));

SlugMap::bumpVersion();
$termBuilder = new LocalizedUrlBuilder($resolver, new SlugMap($wpdb, $registry));

test_ok(
    'a term name translation produces a term slug',
    $termBuilder->addPrefix('/category/lingowp-test-topic/', 'zz_ZZ') === '/zz/category/lingowp-zed-sujet/',
    $termBuilder->addPrefix('/category/lingowp-test-topic/', 'zz_ZZ')
);
test_ok(
    'the category base itself is left alone (it is a route, not a slug)',
    str_contains($termBuilder->addPrefix('/category/lingowp-test-topic/', 'zz_ZZ'), '/category/'),
    $termBuilder->addPrefix('/category/lingowp-test-topic/', 'zz_ZZ')
);

// ── 4c. Draft-language preview ───────────────────────────────────────────────
// An editor previewing an UNPUBLISHED language rides an unprefixed source-slug
// path. Nothing about it may become a translated public URL.

test_section('preview: a draft language keeps source slugs');

$_SERVER['REQUEST_URI'] = '/lingowp-test-about/?lingowp_preview=qq_QQ';
$_GET['lingowp_preview'] = 'qq_QQ';

$previewMap = new SlugMap($wpdb, $registry);
test_ok(
    'a draft-language preview path is not slug-mapped',
    $previewMap->localize('/lingowp-test-about/', 'qq_QQ') === '/lingowp-test-about/',
    $previewMap->localize('/lingowp-test-about/', 'qq_QQ')
);
test_ok(
    'and the draft language still mints no prefixed URL',
    $builder->addPrefix('/lingowp-test-about/', 'qq_QQ') === '/lingowp-test-about/',
    $builder->addPrefix('/lingowp-test-about/', 'qq_QQ')
);

unset($_GET['lingowp_preview']);
$_SERVER['REQUEST_URI'] = '/';

// ── 4d. The translated front page ────────────────────────────────────────────

test_section('front page: the language home has no slug to translate');

test_ok(
    'the language home is just the prefix',
    $builder->addPrefix('/', 'zz_ZZ') === '/zz/',
    $builder->addPrefix('/', 'zz_ZZ')
);
test_ok(
    'and it round-trips back to the root',
    $builder->stripPrefix('/zz/') === '/',
    $builder->stripPrefix('/zz/')
);

// ── 5. The feature switch ────────────────────────────────────────────────────

test_section('the feature is off by default');

update_option(SlugMap::OPTION_ENABLED, false);

test_ok(
    'with the option off, nothing is mapped',
    $slugMap->localize('/lingowp-test-about/', 'zz_ZZ') === '/lingowp-test-about/',
    $slugMap->localize('/lingowp-test-about/', 'zz_ZZ')
);
test_ok(
    'with the option off, the prefix still works as before',
    $builder->addPrefix('/lingowp-test-about/', 'zz_ZZ') === '/zz/lingowp-test-about/',
    $builder->addPrefix('/lingowp-test-about/', 'zz_ZZ')
);

test_ok(
    'with the option off, the incoming path is untouched',
    test_normalized_path($registrar, '/zz/lingowp-zed-apropos/') === '/zz/lingowp-zed-apropos/',
    test_normalized_path($registrar, '/zz/lingowp-zed-apropos/')
);

test_done();

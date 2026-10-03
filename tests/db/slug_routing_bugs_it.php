<?php

/**
 * REPRODUCTION SUITE for the slug-translation findings from the gpt-6-astra
 * cross-review (2026-10-03). Every assertion here states the DESIRED behavior,
 * so a FAIL means "bug reproduced" and the file is expected to be red until the
 * fixes land.
 *
 * Deliberately NOT registered in composer.json's test:db yet: it would turn the
 * suite red. Register it in the same change that fixes these, at which point it
 * must go all-green.
 *
 * Findings covered, in the review's numbering:
 *   1  verbose page rules reject the translated path before 'request' runs
 *   2  WP_Query re-copies a CPT query var over the corrected 'name'
 *   3  outgoing mapping ignores a segment's ROLE (rewrite bases, pagination)
 *   4a _wp_old_slug aliases are not reserved
 *   4b non-'publish' but reachable statuses are not reserved
 *   5  sanitize_text_field() destroys percent-encoded (non-Latin) paths
 *   6  attachment create/edit never invalidates the map
 *   7  disable -> change -> re-enable resurrects a stale map
 *   8  the per-request memo ignores a version bump
 *   9  deleteTranslation() bypasses invalidation
 *   11 two live parent rows for one title pick an arbitrary translation
 *   12 a DEFAULT-language page load pays for every target language's map
 *
 * Second review (same day) added:
 *   R1 the language PREFIX itself is mapped, in both directions
 *   R2 a segment's ROLE is ignored: route ARGUMENTS (author nicename, search
 *      term, comment page) are mapped as though they were object slugs, and
 *      conversely a legitimate object slug is blocked merely for resembling a
 *      route base
 *   R3 copyFallbackTranslations() bumps the cache version BEFORE it writes
 *
 * Not reproducible in this harness, inspection-only (stated for completeness):
 *   10 did_action('init') is already truthy inside the first init callback
 *      (plugin.php increments before dispatch), so a late-registered post type
 *      can be missed and then cached. Needs a real boot sequence.
 *
 * Run: wp eval-file tests/db/slug_routing_bugs_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Language\Infrastructure\SupportedLanguages;
use LingoWP\Language\Application\ResolveRequestLanguage as TestResolver;
use LingoWP\LocalizationRouting\LanguageLinks;
use LingoWP\LocalizationRouting\LanguageRouteRegistrar;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;
use LingoWP\LocalizationRouting\SlugMap;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Text\TranslationKey;

test_guard();
test_begin();

global $wpdb, $wp_rewrite;

$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();

$store->save(['code' => 'zz_ZZ', 'slug' => 'zz']);
$registry->enableLanguage('zz_ZZ');

$default = $registry->getDefaultLanguage();
update_option(SlugMap::OPTION_ENABLED, true);

/**
 * Seed one post/page plus (optionally) its post_title source row and
 * translation. Returns [post id, parent row id].
 *
 * @return array{0:int,1:int}
 */
function test_seed(
    string $slug,
    string $title,
    ?string $translation,
    string $lang = 'zz_ZZ',
    string $type = 'page',
    string $status = 'publish'
): array {
    global $wpdb;

    $now = current_time('mysql');

    $postId = test_insert($wpdb->posts, [
        'post_author'       => 1,
        'post_date'         => $now,
        'post_date_gmt'     => $now,
        'post_content'      => '',
        'post_title'        => $title,
        'post_excerpt'      => '',
        'post_status'       => $status,
        'comment_status'    => 'closed',
        'ping_status'       => 'closed',
        'post_name'         => $slug,
        'post_modified'     => $now,
        'post_modified_gmt' => $now,
        'post_parent'       => 0,
        'guid'              => '',
        'menu_order'        => 0,
        'post_type'         => $type,
        'comment_count'     => 0,
    ]);

    if ($translation === null) {
        return [$postId, 0];
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
    $parentRowId = (int) $wpdb->insert_id;

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$child} (original_hash, lang_code, translated_text, status, created_at, updated_at)
         VALUES (UNHEX(%s), %s, %s, 1, %s, %s)",
        $hashHex,
        $lang,
        $translation,
        $now,
        $now
    ));

    return [$postId, $parentRowId];
}

/** A fresh map, bypassing any per-request memo. */
function test_fresh_map(OptionLanguageRegistry $registry): SlugMap
{
    global $wpdb;
    return new SlugMap($wpdb, $registry);
}

/** A fresh map + builder pair, bypassing any per-request memo. */
function test_fresh_builder(OptionLanguageRegistry $registry): LocalizedUrlBuilder
{
    global $wpdb;
    $resolver = new ResolveRequestLanguage($registry);
    return new LocalizedUrlBuilder($resolver, new SlugMap($wpdb, $registry));
}

// ── Fixtures ─────────────────────────────────────────────────────────────────

// 1 + 2: a page whose title translates to a different slug.
[$aboutId] = test_seed('lingowp-test-about', 'Lingowp Test About', 'Lingowp Zed Apropos');

// 3: real objects whose SOURCE slugs are rewrite-grammar tokens.
test_seed('category', 'Lingowp Test Category Word', 'Lingowp Zed Categorie');
test_seed('author', 'Lingowp Test Author Word', 'Lingowp Zed Auteur');
test_seed('2', 'Lingowp Test Two', 'Lingowp Zed Deux');

// 4a: a renamed post keeps its old URL via _wp_old_slug; another page derives it.
[$renamedId] = test_seed('lingowp-test-company', 'Lingowp Test Company', null);
add_post_meta($renamedId, '_wp_old_slug', 'lingowp-test-oldslug');
test_seed('lingowp-test-claim-old', 'Lingowp Test Claim Old', 'Lingowp Test Oldslug');

// 4b: a private page's slug, claimed by a published page's derived slug.
test_seed('lingowp-test-private', 'Lingowp Test Private Owner', null, 'zz_ZZ', 'page', 'private');
test_seed('lingowp-test-claim-priv', 'Lingowp Test Claim Priv', 'Lingowp Test Private');

// 5: an ASCII source slug whose translation is non-Latin (percent-encoded).
test_seed('lingowp-test-arabic', 'Lingowp Test Arabic', 'عن الشركة');

// 11: two live parent rows for one post's title, each with its own translation.
//
// Row order is NOT insertion order and NOT owner id (both rows share one post):
// the join reads the parent through uniq_source (post_id, field, original_hash),
// so rows come back in ORIGINAL_HASH BYTE ORDER. That is the finding — there is
// no tie-break selecting the post's current title — and it also means a naive
// fixture passes or fails by accident depending on which title happens to hash
// lower. So pick a stale title whose hash sorts BEFORE the current one, making
// "the stale row wins" deterministic.
$parentTable = TranslationMemorySchema::parentTables()['post'];
$childTable  = $parentTable . '_translation';
$now         = current_time('mysql');

$currentTitle = 'Lingowp Test Tworows New';
$currentHash  = TranslationKey::currentHash($currentTitle);
$staleTitle   = null;
for ($i = 1; $i <= 500; $i++) {
    $candidate = 'Lingowp Test Tworows Old ' . $i;
    if (strcmp(TranslationKey::currentHash($candidate), $currentHash) < 0) {
        $staleTitle = $candidate;
        break;
    }
}

test_ok(
    'fixture: found a stale title hashing before the current one',
    $staleTitle !== null,
    (string) $staleTitle
);

[$twoRowId] = test_seed('lingowp-test-tworows', $currentTitle, null);

foreach ([[(string) $staleTitle, 'Lingowp Zed Old'], [$currentTitle, 'Lingowp Zed New']] as [$titleText, $translated]) {
    $rowHash = bin2hex(TranslationKey::currentHash($titleText));
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$parentTable} (post_id, field, original_hash, original_text, status, created_at, updated_at)
         VALUES (%d, 'post_title', UNHEX(%s), %s, 0, %s, %s)",
        $twoRowId,
        $rowHash,
        $titleText,
        $now,
        $now
    ));
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$childTable} (original_hash, lang_code, translated_text, status, created_at, updated_at)
         VALUES (UNHEX(%s), %s, %s, 1, %s, %s)",
        $rowHash,
        'zz_ZZ',
        $translated,
        $now,
        $now
    ));
}

SlugMap::bumpVersion();

$builder   = test_fresh_builder($registry);
$registrar = new LanguageRouteRegistrar(new ResolveRequestLanguage($registry), test_fresh_map($registry));

test_ok('fixtures: derived slug for the about page is live', $builder->addPrefix('/lingowp-test-about/', 'zz_ZZ') === '/zz/lingowp-zed-apropos/', $builder->addPrefix('/lingowp-test-about/', 'zz_ZZ'));

// ── Finding 1: verbose page rules ────────────────────────────────────────────

test_section('F1: a translated PAGE url must resolve through real rule matching');

// Precondition: this permalink structure is what turns verbose page rules on,
// and the prefixed clones must exist. Without both, the repro means nothing.
test_ok(
    'precondition: permalink structure triggers verbose page rules',
    (bool) preg_match('/^[^%]*%(?:postname|category|tag|author)%/', (string) get_option('permalink_structure')),
    (string) get_option('permalink_structure')
);
test_ok('precondition: verbose page rules are on', $wp_rewrite->use_verbose_page_rules === true);

update_option('rewrite_rules', '');
$rules = $wp_rewrite->wp_rewrite_rules();
$hasPrefixed = false;
foreach (array_keys($rules) as $regex) {
    if (strncmp($regex, '^zz/', 4) === 0) {
        $hasPrefixed = true;
        break;
    }
}
test_ok('precondition: prefixed rewrite rules exist', $hasPrefixed);

/** What normalizeRequestPath() leaves in REQUEST_URI, restored afterwards. */
function test_normalized_uri(LanguageRouteRegistrar $registrar, string $uri): string
{
    $_SERVER['REQUEST_URI'] = $uri;
    $registrar->normalizeRequestPath(true);
    $seen = (string) $_SERVER['REQUEST_URI'];
    $registrar->restoreRequestPath();

    return $seen;
}

/** Drive the real request pipeline: rule matching + the 'request' filter. */
function test_resolve(string $uri): array
{
    $_SERVER['REQUEST_URI'] = $uri;

    // CLONE the global $wp, never `new WP()`: register_post_type() registers a
    // custom type's query var on the global instance via $wp->add_query_var(),
    // so a fresh object starts with core's defaults and silently drops every
    // CPT var out of query_vars — which looks exactly like a routing bug.
    $wp = clone $GLOBALS['wp'];
    $wp->query_vars    = [];
    $wp->matched_rule  = '';
    $wp->matched_query = '';
    $wp->parse_request();

    $query = new WP_Query();
    $query->query($wp->query_vars);

    return [
        'vars' => $wp->query_vars,
        'id'   => (int) ($query->posts[0]->ID ?? 0),
        'is404' => $query->posts === [],
    ];
}

$r = test_resolve('/zz/lingowp-zed-apropos/');
test_ok(
    'the translated page url resolves to the page',
    $r['id'] === $aboutId,
    'resolved=' . $r['id'] . ' expected=' . $aboutId . ' vars=' . wp_json_encode($r['vars'])
);

$r = test_resolve('/zz/lingowp-test-about/');
test_ok(
    'the source-slug url under the prefix still resolves (alias must never break)',
    $r['id'] === $aboutId,
    'resolved=' . $r['id'] . ' expected=' . $aboutId . ' vars=' . wp_json_encode($r['vars'])
);

// The normalization rewrites $_SERVER to route the request; everything after
// parse_request() — redirect_canonical's comparison, hreflang, the switcher —
// must see the URL the visitor actually asked for. This asserts the restore is
// WIRED, which calling restoreRequestPath() directly cannot.
test_resolve('/zz/lingowp-zed-apropos/');
test_ok(
    'parse_request leaves the real request URI behind it',
    ($_SERVER['REQUEST_URI'] ?? '') === '/zz/lingowp-zed-apropos/',
    (string) ($_SERVER['REQUEST_URI'] ?? '(missing)')
);

// ── Finding 2: CPT query var re-copied over 'name' ───────────────────────────

test_section('F2: a translated CUSTOM POST TYPE slug must survive WP_Query');

register_post_type('lingowp_test_cpt', [
    'public'      => true,
    'query_var'   => 'lingowp_test_cpt',
    'rewrite'     => ['slug' => 'lingowp-test-cpt'],
    'has_archive' => false,
]);

[$cptId] = test_seed('lingowp-test-shoe', 'Lingowp Test Shoe', 'Lingowp Zed Chaussure', 'zz_ZZ', 'lingowp_test_cpt');
SlugMap::bumpVersion();

update_option('rewrite_rules', '');
$wp_rewrite->wp_rewrite_rules();

$r = test_resolve('/zz/lingowp-test-cpt/lingowp-zed-chaussure/');
test_ok(
    'the translated CPT url resolves to the CPT post',
    $r['id'] === $cptId,
    'resolved=' . $r['id'] . ' expected=' . $cptId . ' vars=' . wp_json_encode($r['vars'])
);

// ── Finding 3: segment role is ignored on the way out ────────────────────────

test_section('F3: rewrite-grammar segments must never be slug-mapped');

$b = test_fresh_builder($registry);

test_ok(
    'the category base survives localization',
    $b->addPrefix('/category/news/', 'zz_ZZ') === '/zz/category/news/',
    $b->addPrefix('/category/news/', 'zz_ZZ')
);
test_ok(
    'the author base survives localization',
    $b->addPrefix('/author/someone/', 'zz_ZZ') === '/zz/author/someone/',
    $b->addPrefix('/author/someone/', 'zz_ZZ')
);
test_ok(
    'a numeric pagination segment survives localization',
    $b->addPrefix('/lingowp-test-about/2/', 'zz_ZZ') === '/zz/lingowp-zed-apropos/2/',
    $b->addPrefix('/lingowp-test-about/2/', 'zz_ZZ')
);

// ── Finding 4: the reservation set is too narrow ──────────────────────────────

test_section('F4a: a _wp_old_slug alias must be reserved');

test_ok(
    'no derived slug may take over an old-slug alias',
    test_fresh_map($registry)->toSource('/lingowp-test-oldslug/', 'zz_ZZ') === '/lingowp-test-oldslug/',
    test_fresh_map($registry)->toSource('/lingowp-test-oldslug/', 'zz_ZZ')
);

test_section('F4b: a reachable non-publish slug must be reserved');

test_ok(
    "no derived slug may take over a private page's slug",
    test_fresh_map($registry)->toSource('/lingowp-test-private/', 'zz_ZZ') === '/lingowp-test-private/',
    test_fresh_map($registry)->toSource('/lingowp-test-private/', 'zz_ZZ')
);

// ── Finding 5: percent-encoded paths destroyed by the consumers ───────────────

test_section('F5: a non-Latin translated slug must survive the switcher/hreflang');

$arabicSlug = sanitize_title('عن الشركة');
test_ok(
    'precondition: the translation sanitizes to a percent-encoded slug',
    str_contains($arabicSlug, '%'),
    $arabicSlug
);

$_SERVER['REQUEST_URI'] = '/zz/' . $arabicSlug . '/';
set_query_var('lingowp_lang', 'zz_ZZ');

$resolver = new ResolveRequestLanguage($registry);
$links    = new LanguageLinks($resolver, test_fresh_builder($registry), new SupportedLanguages($store));
$rows     = $links->links();

$defaultRow = null;
foreach ($rows as $row) {
    if ($row['code'] === $default) {
        $defaultRow = $row;
        break;
    }
}

$defaultPath = $defaultRow === null ? '(missing)' : (string) wp_parse_url((string) $defaultRow['url'], PHP_URL_PATH);
test_ok(
    'the switcher points at the real page, not the site root',
    $defaultPath === '/lingowp-test-arabic/',
    $defaultPath
);

set_query_var('lingowp_lang', '');

// ── Finding 11: two live parent rows ─────────────────────────────────────────

test_section('F11: the slug must come from the post CURRENT title translation');

test_ok(
    "a stale parent row must not win the post's slug",
    $b->addPrefix('/lingowp-test-tworows/', 'zz_ZZ') === '/zz/lingowp-zed-new/',
    $b->addPrefix('/lingowp-test-tworows/', 'zz_ZZ')
);

// ── Finding 12: cost on a default-language request ───────────────────────────

test_section('F12: a default-language request must not build target maps');

// Each case bumps the version first: a warm transient would hide the cost and
// make these pass for the wrong reason (the first draft of this file did).
SlugMap::bumpVersion();
$before = $wpdb->num_queries;
test_fresh_builder($registry)->addPrefix('/', $default);
test_ok(
    'localizing for the default language costs no queries',
    $wpdb->num_queries === $before,
    'queries=' . ($wpdb->num_queries - $before)
);

SlugMap::bumpVersion();
$before = $wpdb->num_queries;
test_fresh_builder($registry)->addPrefix('/', 'zz_ZZ');
test_ok(
    'the ROOT path costs no map queries (nothing to map)',
    $wpdb->num_queries === $before,
    'queries=' . ($wpdb->num_queries - $before)
);

// The real consumer: HreflangPresenter renders on EVERY page and loops every
// enabled language. Those alternates genuinely need translated slugs, so the
// map HAS to be built even on a default-language page — that cost is inherent
// and the design answers it by rebuilding rarely (a week-long TTL behind
// precise invalidation), not by pretending it is free. What must hold is that
// the map is built ONCE per language per version: a second render pays nothing.
SlugMap::bumpVersion();
$_SERVER['REQUEST_URI'] = '/lingowp-test-about/';
set_query_var('lingowp_lang', '');

$presenter = new \LingoWP\Resolution\Seo\HreflangPresenter(
    new ResolveRequestLanguage($registry),
    test_fresh_builder($registry),
    new \LingoWP\Resolution\RequestContext()
);

ob_start();
$presenter->renderHreflangTags();
ob_end_clean();

$before = $wpdb->num_queries;
ob_start();
$presenter->renderHreflangTags();
ob_end_clean();

test_ok(
    'a warm hreflang render costs no slug-map queries',
    $wpdb->num_queries === $before,
    'queries=' . ($wpdb->num_queries - $before)
);

// ── Finding 9: repository writes that bypass invalidation ────────────────────

test_section('F9: deleting a translation must invalidate the map');

[$delPostId, $delParentId] = test_seed('lingowp-test-del', 'Lingowp Test Del', 'Lingowp Zed Del');
SlugMap::bumpVersion();

$beforeVersion = (string) get_option('lingowp_slug_map_version', '0');
(new \LingoWP\Database\Repository\WpDbSourceRepository($wpdb, new \LingoWP\Database\Repository\WpDbBoardQueries($wpdb)))
    ->deleteTranslation(SourceRef::make('post', $delParentId), 'zz_ZZ');
$afterVersion = (string) get_option('lingowp_slug_map_version', '0');

test_ok(
    'deleteTranslation() bumps the cache version',
    $afterVersion !== $beforeVersion,
    "before=$beforeVersion after=$afterVersion"
);
test_ok(
    'and the deleted translation stops producing a slug',
    test_fresh_builder($registry)->addPrefix('/lingowp-test-del/', 'zz_ZZ') === '/zz/lingowp-test-del/',
    test_fresh_builder($registry)->addPrefix('/lingowp-test-del/', 'zz_ZZ')
);

// ── Finding 6: attachments ───────────────────────────────────────────────────

test_section('F6: an attachment slug change must invalidate the map');

$beforeVersion = (string) get_option('lingowp_slug_map_version', '0');
$attachmentId  = wp_insert_post([
    'post_title'     => 'Lingowp Test Attachment',
    'post_name'      => 'lingowp-zed-attach',
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'post_mime_type' => 'image/png',
    'post_content'   => '',
]);
$afterVersion = (string) get_option('lingowp_slug_map_version', '0');

test_ok(
    'precondition: the attachment was created',
    is_int($attachmentId) && $attachmentId > 0,
    var_export($attachmentId, true)
);
test_ok(
    'creating an attachment bumps the cache version',
    $afterVersion !== $beforeVersion,
    "before=$beforeVersion after=$afterVersion"
);

// ── Finding 8: the per-request memo ──────────────────────────────────────────

test_section('F8: a version bump must be visible to a live instance');

$memoMap = new SlugMap($wpdb, $registry);
test_ok(
    'precondition: the instance has warmed its memo',
    $memoMap->localize('/lingowp-test-about/', 'zz_ZZ') === '/lingowp-zed-apropos/',
    $memoMap->localize('/lingowp-test-about/', 'zz_ZZ')
);

$wpdb->query($wpdb->prepare(
    "UPDATE {$childTable} c
        JOIN {$parentTable} p ON p.original_hash = c.original_hash
        SET c.translated_text = %s
      WHERE p.post_id = %d AND p.field = 'post_title' AND c.lang_code = %s",
    'Lingowp Zed Changed',
    $aboutId,
    'zz_ZZ'
));
SlugMap::bumpVersion();

test_ok(
    'the same instance picks up the new translation after a bump',
    $memoMap->localize('/lingowp-test-about/', 'zz_ZZ') === '/lingowp-zed-changed/',
    $memoMap->localize('/lingowp-test-about/', 'zz_ZZ')
);

// ── R1: the language prefix is not a slug ────────────────────────────────────
// A page slugged "lingowp-zed-french" whose title translation sanitizes to "zz"
// — the language's own prefix — puts zz => lingowp-zed-french in the reverse
// map. Nothing reserves language prefixes, and normalizeRequestPath() hands the
// WHOLE path (prefix included) to toSource(), so the prefix gets rewritten.

test_section('R1: the language prefix must never be slug-mapped');

test_seed('lingowp-zed-french', 'Lingowp Test French Word', 'zz');
SlugMap::bumpVersion();

$r1Builder = test_fresh_builder($registry);
$r1Map     = test_fresh_map($registry);

test_ok(
    'precondition: the derived slug really is the language prefix',
    sanitize_title('zz') === 'zz'
);

// Incoming: every prefixed URL on the site routes through this.
test_ok(
    'a prefixed path keeps its prefix when normalized',
    $r1Map->toSource('/zz/lingowp-test-about/', 'zz_ZZ') === '/zz/lingowp-test-about/',
    $r1Map->toSource('/zz/lingowp-test-about/', 'zz_ZZ')
);
test_ok(
    'the language home keeps its prefix when normalized',
    $r1Map->toSource('/zz/', 'zz_ZZ') === '/zz/',
    $r1Map->toSource('/zz/', 'zz_ZZ')
);

// And through the real seam, which is what a visitor actually hits.
test_ok(
    'normalizeRequestPath leaves the prefix alone',
    test_normalized_uri($registrar, '/zz/lingowp-test-about/') === '/zz/lingowp-test-about/',
    test_normalized_uri($registrar, '/zz/lingowp-test-about/')
);

// Outgoing: the page whose translation IS a language prefix must not produce a
// URL that looks like a language home.
test_ok(
    'no outgoing URL collapses into a doubled prefix',
    $r1Builder->addPrefix('/lingowp-zed-french/', 'zz_ZZ') === '/zz/lingowp-zed-french/',
    $r1Builder->addPrefix('/lingowp-zed-french/', 'zz_ZZ')
);

// Reserving language prefixes keeps the map from ever holding one, so the
// prefix-detaching half of the fix is belt-and-braces for THAT failure. What it
// uniquely guarantees is this: a language home carries no object slug, so it
// must not build a map at all. Without the detach, '/zz/' went to the mapper
// and paid for a full build on every language-home request.
SlugMap::bumpVersion();
$before = $wpdb->num_queries;
test_normalized_uri($registrar, '/zz/');
test_ok(
    'a language home costs no map queries',
    $wpdb->num_queries === $before,
    'queries=' . ($wpdb->num_queries - $before)
);

// ── R3: invalidate AFTER writing, never before ───────────────────────────────
// copyFallbackTranslations() bumps the version first and copies afterwards, so
// a concurrent request can build — and then cache for a WEEK — a map for the
// new version while the rows are still being written. Observed deterministically
// by asking, at the moment of the bump, whether the data has landed yet.

test_section('R3: the cache version is bumped only after the rows are written');

$store->save(['code' => 'yy_YY', 'slug' => 'yy']);
$registry->enableLanguage('yy_YY');

[$fallbackPostId, $fallbackParentId] = test_seed('lingowp-test-fallback', 'Lingowp Test Fallback', 'Lingowp Zed Fallback');

$repo = new \LingoWP\Database\Repository\WpDbSourceRepository(
    $wpdb,
    new \LingoWP\Database\Repository\WpDbBoardQueries($wpdb)
);

$childCountAtBump = null;
$observer = function () use (&$childCountAtBump, $wpdb, $childTable): void {
    if ($childCountAtBump !== null) {
        return; // first bump only
    }
    $childCountAtBump = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$childTable} WHERE lang_code = %s",
        'yy_YY'
    ));
};
add_action('update_option_lingowp_slug_map_version', $observer);

$copied = $repo->copyFallbackTranslations('zz_ZZ', 'yy_YY');

remove_action('update_option_lingowp_slug_map_version', $observer);

test_ok(
    'precondition: the fallback copy actually copied something',
    $copied > 0,
    'copied=' . $copied
);
test_ok(
    'precondition: the bump happened',
    $childCountAtBump !== null,
    var_export($childCountAtBump, true)
);
test_ok(
    'the rows exist by the time the version changes',
    $childCountAtBump !== null && $childCountAtBump > 0,
    'rows visible at bump time=' . var_export($childCountAtBump, true)
);

// ── R2: a segment's role, not just its spelling ──────────────────────────────
// Mapping is per-segment and role-blind. A blacklist of route tokens can only
// protect BASES; the ARGUMENT after a base is still rewritten as though it were
// an object slug. And the same blacklist is simultaneously too broad: a page
// whose own slug resembles a route base can never translate, anywhere.

test_section('R2: route arguments must never be slug-mapped');

// A page slugged "lingowp-john" whose title translation derives "lingowp-jean".
// Nothing reserves user nicenames, so the reverse map accepts jean => john.
test_seed('lingowp-john', 'Lingowp Jean', 'Lingowp Jean');
// A page whose translation derives a literal comment-page segment.
test_seed('lingowp-cp', 'Lingowp Test Cp', 'Comment Page 2');
// A parent to put a base-shaped slug under. The F3 section already seeded a
// real page slugged "author" (translation "Lingowp Zed Auteur"), and rule 1
// gives the segment to that incumbent — so this expects ITS translation rather
// than seeding a second claimant. Dedicated parent fixture because the F8
// section rewrites the about page's translation, which would make this depend
// on section order instead of on the behaviour under test.
test_seed('lingowp-parentpage', 'Lingowp Test Parentpage', 'Lingowp Zed Parent');

SlugMap::bumpVersion();
$r2Map     = test_fresh_map($registry);
$r2Builder = test_fresh_builder($registry);

test_ok(
    'precondition: the derived slugs are what this case needs',
    sanitize_title('Lingowp Jean') === 'lingowp-jean'
        && sanitize_title('Comment Page 2') === 'comment-page-2',
    sanitize_title('Lingowp Jean') . ' / ' . sanitize_title('Comment Page 2')
);

// An author archive's argument is a user nicename, not a post slug.
test_ok(
    'an author nicename is left alone',
    $r2Map->toSource('/zz/author/lingowp-jean/', 'zz_ZZ') === '/zz/author/lingowp-jean/',
    $r2Map->toSource('/zz/author/lingowp-jean/', 'zz_ZZ')
);

// A search term is free text.
test_ok(
    'a search term is left alone',
    $r2Map->toSource('/zz/search/lingowp-jean/', 'zz_ZZ') === '/zz/search/lingowp-jean/',
    $r2Map->toSource('/zz/search/lingowp-jean/', 'zz_ZZ')
);

// comment-page-2 is a route literal; the blacklist only holds "comment-page".
test_ok(
    'a comment-page segment is left alone',
    $r2Map->toSource('/zz/lingowp-test-about/comment-page-2/', 'zz_ZZ') === '/zz/lingowp-test-about/comment-page-2/',
    $r2Map->toSource('/zz/lingowp-test-about/comment-page-2/', 'zz_ZZ')
);

test_section('R2: and a real slug must translate even if it resembles a base');

// /author/<name>/ is an author archive, but /<page>/author/ is just a page.
// Reserving the word globally blocks the second along with the first.
test_ok(
    'a page slug in a slug POSITION still translates',
    $r2Builder->addPrefix('/lingowp-parentpage/author/', 'zz_ZZ') === '/zz/lingowp-zed-parent/lingowp-zed-auteur/',
    $r2Builder->addPrefix('/lingowp-parentpage/author/', 'zz_ZZ')
);

// ── Finding 7: disable / change / re-enable ──────────────────────────────────
// Last, because it deliberately leaves a stale transient behind.

test_section('F7: re-enabling must not resurrect a pre-disable map');

SlugMap::bumpVersion();
test_ok(
    'precondition: the map claims the derived slug while enabled',
    test_fresh_map($registry)->toSource('/lingowp-zed-changed/', 'zz_ZZ') === '/lingowp-test-about/',
    test_fresh_map($registry)->toSource('/lingowp-zed-changed/', 'zz_ZZ')
);

update_option(SlugMap::OPTION_ENABLED, false);
// A real page now owns that slug — created while the feature was off, so no
// invalidation hook was registered to notice it.
test_seed('lingowp-zed-changed', 'Lingowp Test Steals Back', null);
update_option(SlugMap::OPTION_ENABLED, true);

test_ok(
    'after re-enabling, the real owner of that slug is not shadowed',
    test_fresh_map($registry)->toSource('/lingowp-zed-changed/', 'zz_ZZ') === '/lingowp-zed-changed/',
    test_fresh_map($registry)->toSource('/lingowp-zed-changed/', 'zz_ZZ')
);

test_done();

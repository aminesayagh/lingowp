<?php

/**
 * Translated pages in the core XML sitemap (wp-sitemap.xml): one URL per
 * published language, the same URL hreflang emits, untranslated pages left out
 * exactly where LanguageAwareRobotsMeta marks them noindex.
 * Run: wp eval-file tests/db/sitemap_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\Repository\WpDbBoardQueries;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Extraction\Structured\StructuredSourceScanner;
use LingoWP\Shared\Parsing\HtmlUnitExtractor;
use LingoWP\Shared\Parsing\TranslationUnitCodec;
use LingoWP\Shared\Text\TranslationKey;
use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\LocalizationRouting\LocalizedUrlBuilder;
use LingoWP\LocalizationRouting\SlugMap;
use LingoWP\Resolution\RequestContext;
use LingoWP\Resolution\Seo\HreflangPresenter;
use LingoWP\Resolution\Seo\LanguageSitemapProvider;

global $wpdb;

test_guard();
test_begin();

$repo    = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
$scanner = new StructuredSourceScanner($repo, new HtmlUnitExtractor(new TranslationUnitCodec()));
$parents = TranslationMemorySchema::parentTables();
$PP      = $parents['post'];
$now     = current_time('mysql');

$makePost = static function (string $title, string $type = 'page') use ($wpdb, $now, $scanner): int {
    $id = test_insert($wpdb->posts, [
        'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => $now,
        'post_content' => '<!-- wp:paragraph --><p>' . esc_html($title) . ' body.</p><!-- /wp:paragraph -->',
        'post_title' => $title, 'post_excerpt' => '', 'post_status' => 'publish',
        'comment_status' => 'closed', 'ping_status' => 'closed',
        'post_name' => sanitize_title($title) . '-' . strtolower(wp_generate_password(6, false)),
        'post_modified' => $now, 'post_modified_gmt' => $now, 'post_type' => $type,
    ]);
    clean_post_cache($id);
    $scanner->refreshPost($id);
    return $id;
};

$translate = static function (string $source, string $lang, string $text) use ($wpdb, $PP): void {
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$PP}_translation (original_hash, lang_code, translated_text, status, created_at, updated_at)
         VALUES (UNHEX(%s), %s, %s, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text)",
        bin2hex(TranslationKey::currentHash($source)), $lang, $text
    ));
};

// ── 1. translatedPostIds() agrees with the per-post noindex check ─────────

test_section('translatedPostIds() matches hasAnyTranslation():');

$translatedId   = $makePost('LINGOWP_TEST sitemap translated');
$untranslatedId = $makePost('LINGOWP_TEST sitemap untranslated');
$otherLangId    = $makePost('LINGOWP_TEST sitemap other language');
$translate('LINGOWP_TEST sitemap translated', 'fr_FR', 'LINGOWP_TEST plan traduit');
$translate('LINGOWP_TEST sitemap other language', 'es_GT', 'LINGOWP_TEST otro idioma');

$frIds = $repo->translatedPostIds('fr_FR');
test_ok('a post with a French unit is in the French set', in_array($translatedId, $frIds, true));
test_ok('a post with no translation is not', !in_array($untranslatedId, $frIds, true));
test_ok('a post translated only in another language is not', !in_array($otherLangId, $frIds, true));

$allIds = array_map('intval', $wpdb->get_col("SELECT DISTINCT post_id FROM {$PP} UNION SELECT DISTINCT post_id FROM {$parents['meta']}"));
$languages = (new \LingoWP\Language\Infrastructure\OptionLanguageRegistry())->getEnabledLanguages();
$mismatches = [];
foreach ($languages as $lang) {
    $set = array_flip($repo->translatedPostIds($lang));
    foreach ($allIds as $id) {
        if (isset($set[$id]) !== $repo->hasAnyTranslation($id, $lang)) {
            $mismatches[] = "{$id}/{$lang}";
        }
    }
}
test_ok('agrees with hasAnyTranslation() on every post in the dev DB, every enabled language ('
    . count($allIds) . ' posts × ' . count($languages) . ')', $mismatches === [], 'mismatches ' . json_encode(array_slice($mismatches, 0, 10)));

// ── 2. The "languages" provider ─────────────────────────────────────────────
// zz_ZZ and yy_YY published, qq_QQ draft — not real locales, so they cannot
// collide with this dev site (same convention as slug_translation_it.php).

test_section('language sitemap provider: index:');

$store    = new LanguageMetadataStore();
$registry = new OptionLanguageRegistry();
$store->save(['code' => 'zz_ZZ', 'slug' => 'zz']);
$registry->enableLanguage('zz_ZZ');
$store->save(['code' => 'yy_YY', 'slug' => 'yy']);
$registry->enableLanguage('yy_YY');
$store->save(['code' => 'qq_QQ', 'slug' => 'qq']); // draft
update_option(SlugMap::OPTION_ENABLED, true);

$aboutId = $makePost('LINGOWP_TEST Sitemap About');
$plainId = $makePost('LINGOWP_TEST Sitemap Plain');
$translate('LINGOWP_TEST Sitemap About', 'zz_ZZ', 'LINGOWP_TEST Zed Apropos');
SlugMap::bumpVersion();

// An SEO plugin on this dev site switches core sitemaps off (wp_sitemaps_enabled),
// so the shared server has no core providers. A private server with sitemaps
// forced on gives core's real posts/taxonomies/users providers to test against.
add_filter('wp_sitemaps_enabled', '__return_true', PHP_INT_MAX);
$server = new WP_Sitemaps();
$server->register_sitemaps();
remove_filter('wp_sitemaps_enabled', '__return_true', PHP_INT_MAX);

$newProvider = static function () use ($registry, $wpdb, $repo, $server): array {
    $resolver = new ResolveRequestLanguage($registry);
    $builder  = new LocalizedUrlBuilder($resolver, new SlugMap($wpdb, $registry));
    $provider = new LanguageSitemapProvider($resolver, $builder, $repo);
    $ref      = new ReflectionProperty($provider, 'registry');
    $ref->setAccessible(true);
    $ref->setValue($provider, $server->registry); // what register()'s wp_sitemaps_init callback sets
    return [$provider, $resolver, $builder];
};

update_option('show_on_front', 'page');
[$provider, $resolver, $builder] = $newProvider();
$names = array_column($provider->get_sitemap_type_data(), 'name');

test_ok('the plugin registers the provider with core', wp_sitemaps_get_server()->registry->get_provider('languages') instanceof LanguageSitemapProvider);
test_ok('a published language gets its page sitemap', in_array('zz_zz-posts-page', $names, true), 'got ' . json_encode($names));
test_ok('a draft language gets nothing', preg_grep('/^qq_qq-/', $names) === [], 'got ' . json_encode($names));
test_ok('the default language gets nothing (core already lists it)',
    preg_grep('/^' . preg_quote(strtolower($registry->getDefaultLanguage()), '/') . '-/', $names) === [], 'got ' . json_encode($names));
test_ok('a published language with no translated posts lists no posts sitemap (it would 404)',
    preg_grep('/^yy_yy-posts-/', $names) === [], 'got ' . json_encode($names));

$coreTaxonomies = array_filter($server->registry->get_provider('taxonomies')->get_sitemap_type_data(), static fn($t) => $t['pages'] > 0);
if ($coreTaxonomies !== []) {
    $first = reset($coreTaxonomies)['name'];
    test_ok("term archives are listed even with nothing translated (never noindex): yy_yy-taxonomies-{$first}",
        in_array("yy_yy-taxonomies-{$first}", $names, true), 'got ' . json_encode($names));
}
$allNonEmpty = static function (LanguageSitemapProvider $provider): array {
    $empty = [];
    foreach ($provider->get_sitemap_type_data() as $type) {
        for ($page = 1; $page <= $type['pages']; $page++) {
            if ($provider->get_url_list($page, $type['name']) === []) {
                $empty[] = "{$type['name']}#{$page}";
            }
        }
    }
    return $empty;
};
test_ok('every advertised sitemap page is non-empty (core 404s an empty one)', ($e = $allNonEmpty($provider)) === [], 'empty: ' . json_encode($e));

test_section('language sitemap provider: URL list:');

// Last page of core's page sitemap — the fixtures are the newest pages.
$hookCount = static fn(): int => array_sum(array_map(
    static fn(string $hook): int => isset($GLOBALS['wp_filter'][$hook]) ? count($GLOBALS['wp_filter'][$hook]->callbacks, COUNT_RECURSIVE) : 0,
    ['wp_sitemaps_posts_query_args', 'wp_sitemaps_posts_pre_url_list', 'wp_sitemaps_posts_pre_max_num_pages']
));
$hooksBefore = $hookCount();
$lastPage = $provider->get_max_num_pages('zz_zz-posts-page');
$locs     = array_column($provider->get_url_list($lastPage, 'zz_zz-posts-page'), 'loc');

$aboutPath = (string) wp_parse_url(get_permalink($aboutId), PHP_URL_PATH);
$plainPath = (string) wp_parse_url(get_permalink($plainId), PHP_URL_PATH);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = $aboutPath;
ob_start();
(new HreflangPresenter($resolver, $builder, new RequestContext()))->renderHreflangTags();
preg_match('/hreflang="zz-ZZ" href="([^"]+)"/', (string) ob_get_clean(), $m);
$hreflangHref = html_entity_decode($m[1] ?? '');

test_ok('a translated page is listed with its translated slug',
    (bool) preg_grep('#/zz/lingowp_test-zed-apropos/$#', $locs), 'got ' . json_encode(array_slice($locs, -5)));
test_ok('its sitemap URL is exactly its hreflang URL', $hreflangHref !== '' && in_array($hreflangHref, $locs, true),
    'hreflang=' . $hreflangHref . ' locs=' . json_encode(array_slice($locs, -5)));
test_ok('an untranslated page (noindex in zz) is left out',
    preg_grep('#' . preg_quote(trim($plainPath, '/'), '#') . '#', $locs) === [], 'got ' . json_encode(array_slice($locs, -5)));
test_ok('every listed URL carries the /zz/ prefix', $locs !== [] && count(preg_grep('#^https?://[^/]+(/[^/]+)?/zz/#', $locs)) === count($locs),
    'got ' . json_encode(array_slice($locs, 0, 5)));
test_ok('an unknown subtype lists nothing', $provider->get_url_list(1, 'nope-posts-page') === []);
test_ok('the temporary query restriction is removed afterwards', $hookCount() === $hooksBefore);

// Codex review #5: a caching plugin answering core's short-circuit with the
// unrestricted list must not smuggle the untranslated (noindex) page back in.
$cached = static fn() => [['loc' => get_permalink($aboutId)], ['loc' => get_permalink($plainId)]];
add_filter('wp_sitemaps_posts_pre_url_list', $cached, 10);
add_filter('wp_sitemaps_posts_pre_max_num_pages', static fn() => 1, 10);
[$cachedProvider] = $newProvider();
$cachedLocs = array_column($cachedProvider->get_url_list($cachedProvider->get_max_num_pages('zz_zz-posts-page'), 'zz_zz-posts-page'), 'loc');
remove_all_filters('wp_sitemaps_posts_pre_url_list', 10);
remove_all_filters('wp_sitemaps_posts_pre_max_num_pages', 10);
test_ok('a short-circuited (cached) core list cannot add the untranslated page',
    in_array($hreflangHref, $cachedLocs, true) && preg_grep('#' . preg_quote(trim($plainPath, '/'), '#') . '#', $cachedLocs) === [],
    'got ' . json_encode(array_slice($cachedLocs, -3)));

// Codex review #4: core pages over ALL posts; with 1 URL per sitemap page and
// only one translated page, the zz page sitemap must be exactly 1 non-empty page.
add_filter('wp_sitemaps_max_urls', $oneUrl = static fn() => 1, 10);
[$tinyProvider] = $newProvider();
test_ok('paging counts only translated posts (1 per page → 1 page, not one per published page)',
    $tinyProvider->get_max_num_pages('zz_zz-posts-page') === 1, 'got ' . $tinyProvider->get_max_num_pages('zz_zz-posts-page'));
test_ok('…and that page is the translated one', array_column($tinyProvider->get_url_list(1, 'zz_zz-posts-page'), 'loc') === [$hreflangHref]);
remove_filter('wp_sitemaps_max_urls', $oneUrl, 10);

// Codex review #7: a translated page moved to draft keeps its source rows; it
// must not keep an (empty, 404ing) page sitemap advertised.
$draftId = $makePost('LINGOWP_TEST Sitemap Drafted');
$translate('LINGOWP_TEST Sitemap Drafted', 'yy_YY', 'LINGOWP_TEST Yy Brouillon');
$wpdb->update($wpdb->posts, ['post_status' => 'draft'], ['ID' => $draftId]);
clean_post_cache($draftId);
[$draftProvider] = $newProvider();
test_ok('a language whose only translated page is now a draft lists no page sitemap',
    !in_array('yy_yy-posts-page', array_column($draftProvider->get_sitemap_type_data(), 'name'), true));

test_section('language sitemap provider: home on a latest-posts front page:');

update_option('show_on_front', 'posts');
[$provider] = $newProvider();
$names = array_column($provider->get_sitemap_type_data(), 'name');
test_ok('the page sitemap stays listed with nothing translated, because it carries the home URL',
    in_array('yy_yy-posts-page', $names, true), 'got ' . json_encode($names));
$homeLocs = array_column($provider->get_url_list(1, 'yy_yy-posts-page'), 'loc');
test_ok('it lists the language home', in_array($builder->absoluteUrl($builder->addPrefix(LocalizedUrlBuilder::sitePath() . '/', 'yy_YY')), $homeLocs, true),
    'got ' . json_encode(array_slice($homeLocs, 0, 3)));

// ── 3. "Prefix default language": core's own entries follow hreflang ──────────

test_section('prefix default language: core entries:');

update_option('show_on_front', 'page');
[$provider, $resolver, $builder] = $newProvider();
$default   = $registry->getDefaultLanguage();
$aboutLoc  = home_url($aboutPath);

test_ok('option off: a core entry is left exactly as core built it',
    $provider->localizeDefaultEntry(['loc' => $aboutLoc]) === ['loc' => $aboutLoc]);

update_option('lingowp_prefix_default_language', true);
[$provider, $resolver, $builder] = $newProvider();

ob_start();
(new HreflangPresenter($resolver, $builder, new RequestContext()))->renderHreflangTags();
preg_match('/hreflang="' . preg_quote(str_replace('_', '-', $default), '/') . '" href="([^"]+)"/', (string) ob_get_clean(), $m);
$defaultHref = html_entity_decode($m[1] ?? '');

$entry = $provider->localizeDefaultEntry(['loc' => $aboutLoc, 'lastmod' => '2026-01-01']);
test_ok('option on: a core entry gets the default language prefix, exactly the hreflang URL',
    $defaultHref !== '' && $entry['loc'] === $defaultHref, 'entry=' . $entry['loc'] . ' hreflang=' . $defaultHref);
test_ok('other entry fields are kept', ($entry['lastmod'] ?? null) === '2026-01-01');
test_ok('the plugin hooks it into all four core entry filters',
    count(array_filter(['wp_sitemaps_posts_entry', 'wp_sitemaps_posts_show_on_front_entry', 'wp_sitemaps_taxonomies_entry', 'wp_sitemaps_users_entry'],
        static fn(string $hook): bool => (bool) has_filter($hook))) === 4);

$coreLocs = array_column($server->registry->get_provider('posts')->get_url_list($lastPage, 'page'), 'loc');
$prefix   = '/' . trim((string) wp_parse_url($builder->addPrefix(LocalizedUrlBuilder::sitePath() . '/', $default), PHP_URL_PATH), '/') . '/';
test_ok('through core itself: every page entry carries the default prefix',
    $coreLocs !== [] && count(array_filter($coreLocs, static fn(string $loc): bool => strpos((string) wp_parse_url($loc, PHP_URL_PATH), $prefix) === 0)) === count($coreLocs),
    'prefix=' . $prefix . ' got ' . json_encode(array_slice($coreLocs, -3)));

$locs = array_column($provider->get_url_list($lastPage, 'zz_zz-posts-page'), 'loc');
test_ok('the zz sitemap is unaffected: still /zz/, never /en/zz/ or /zz/en/',
    in_array($hreflangHref, $locs, true), 'got ' . json_encode(array_slice($locs, -3)));

update_option('lingowp_prefix_default_language', false);

test_done();

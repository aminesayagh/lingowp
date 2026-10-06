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

// ── 1. Batch "has any translation" agrees with the per-post check ──────────

test_section('postIdsWithAnyTranslation() matches hasAnyTranslation():');

$translatedId   = $makePost('LINGOWP_TEST sitemap translated');
$untranslatedId = $makePost('LINGOWP_TEST sitemap untranslated');
$otherLangId    = $makePost('LINGOWP_TEST sitemap other language');
$translate('LINGOWP_TEST sitemap translated', 'fr_FR', 'LINGOWP_TEST plan traduit');
$translate('LINGOWP_TEST sitemap other language', 'es_GT', 'LINGOWP_TEST otro idioma');

$batch = $repo->postIdsWithAnyTranslation([$translatedId, $untranslatedId, $otherLangId, 0, -3], 'fr_FR');
test_ok('a post with a French unit is in the French set', isset($batch[$translatedId]));
test_ok('a post with no translation is not', !isset($batch[$untranslatedId]));
test_ok('a post translated only in another language is not', !isset($batch[$otherLangId]));
test_ok('an empty id list costs nothing and returns nothing', $repo->postIdsWithAnyTranslation([], 'fr_FR') === []);

$allIds = array_map('intval', $wpdb->get_col("SELECT DISTINCT post_id FROM {$PP} UNION SELECT DISTINCT post_id FROM {$parents['meta']}"));
$languages = (new \LingoWP\Language\Infrastructure\OptionLanguageRegistry())->getEnabledLanguages();
$mismatches = [];
foreach ($languages as $lang) {
    $batch = $repo->postIdsWithAnyTranslation($allIds, $lang);
    foreach ($allIds as $id) {
        if (isset($batch[$id]) !== $repo->hasAnyTranslation($id, $lang)) {
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
test_ok('paging matches core\'s own page sitemap',
    $provider->get_max_num_pages('zz_zz-posts-page') === $server->registry->get_provider('posts')->get_max_num_pages('page'));

test_section('language sitemap provider: URL list:');

// Last page of core's page sitemap — the fixtures are the newest pages.
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
test_ok('the temporary post-id hook is removed afterwards', !has_filter('wp_sitemaps_posts_entry'));

test_section('language sitemap provider: home on a latest-posts front page:');

update_option('show_on_front', 'posts');
[$provider] = $newProvider();
$names = array_column($provider->get_sitemap_type_data(), 'name');
test_ok('the page sitemap stays listed with nothing translated, because it carries the home URL',
    in_array('yy_yy-posts-page', $names, true), 'got ' . json_encode($names));
$homeLocs = array_column($provider->get_url_list(1, 'yy_yy-posts-page'), 'loc');
test_ok('it lists the language home', in_array($builder->absoluteUrl($builder->addPrefix(LocalizedUrlBuilder::sitePath() . '/', 'yy_YY')), $homeLocs, true),
    'got ' . json_encode(array_slice($homeLocs, 0, 3)));

test_done();

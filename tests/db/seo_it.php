<?php

/**
 * lingowp-seo add-on coverage — the `lingowp_translatable_term_meta_keys`
 * allow-list Rank Math's term-level SEO/social overrides ride, plus one
 * end-to-end discovery+render pass through core's generic term-meta pipeline
 * (the same Term::get_meta() -> real get_term_metadata fallback chain proven
 * for the breadcrumb key, now extended to the 4 social keys).
 * Run: wp eval-file tests/db/seo_it.php
 *
 * TEMPORARY HOME, by request (see lingowp_addon_test_consolidation_pref
 * memory): exercises the separate lingowp-seo add-on's filter registration
 * (not core), kept here so it runs via one `composer test:db` during active
 * development. Move back to lingowp-seo/tests/ once the add-on stabilizes.
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Shared\Source\TranslatableTermMetaKeys;

global $wpdb;

test_guard();

if (!class_exists('LingoWPSeo\\RankMathAuthorBreadcrumbResolver')) {
    fwrite(STDERR, "SKIPPED: lingowp-seo add-on is not active.\n");
    exit(0);
}

test_begin();

test_section('Rank Math term-meta allow-list:');

$keys = TranslatableTermMetaKeys::keys();

foreach ([
    'rank_math_breadcrumb_title',
    'rank_math_title',
    'rank_math_description',
    'rank_math_facebook_title',
    'rank_math_facebook_description',
    'rank_math_twitter_title',
    'rank_math_twitter_description',
] as $key) {
    test_ok("term-meta allow-list includes {$key}", in_array($key, $keys, true));
}

test_section('end-to-end: a new social key discovered and rendered:');

$termId = test_insert($wpdb->terms, [
    'name'       => 'LingoWP IT SEO term',
    'slug'       => 'lingowp-it-seo-term-' . wp_generate_password(6, false),
    'term_group' => 0,
]);
test_insert($wpdb->term_taxonomy, [
    'term_id'     => $termId,
    'taxonomy'    => 'category',
    'description' => '',
    'parent'      => 0,
    'count'       => 0,
]);

$sentinel = 'LINGOWP_TEST ZQSEOIT facebook title sentinel.';
update_term_meta($termId, 'rank_math_facebook_title', $sentinel);

$scanner = new \LingoWP\Extraction\Structured\StructuredSourceScanner(
    \LingoWP\Database\Repository\WpDbSourceRepository::instance(),
    new \LingoWP\Shared\Parsing\HtmlUnitExtractor(new \LingoWP\Shared\Parsing\TranslationUnitCodec())
);
$scanner->refreshTermMeta($termId);

$TMP = TranslationMemorySchema::parentTables()['term_meta'];
$found = test_var(
    "SELECT id FROM {$TMP} WHERE term_id = %d AND meta_key_hash = UNHEX(%s) AND context IS NULL AND original_text = %s",
    $termId, bin2hex(hash('sha256', 'rank_math_facebook_title', true)), $sentinel
);
test_ok('rank_math_facebook_title is discovered by core\'s term-meta scanner', $found !== null);

$now  = current_time('mysql');
$lang = 'fr_FR';

test_insert($TMP . '_translation', [
    'parent_id'       => $found,
    'lang_code'       => $lang,
    'translated_text' => 'LINGOWP_TEST ZQSEOIT titre facebook traduit',
    'status'          => 2,
    'created_at'      => $now,
    'updated_at'      => $now,
]);

$resolver = new \LingoWP\Resolution\StructuredRenderResolver(
    new \LingoWP\Language\Application\ResolveRequestLanguage($langRegistry = new \LingoWP\Language\Infrastructure\OptionLanguageRegistry()),
    new \LingoWP\Resolution\RequestContext(),
    \LingoWP\Database\Repository\WpDbSourceRepository::instance(),
    new \LingoWP\Shared\Parsing\HtmlUnitExtractor(new \LingoWP\Shared\Parsing\TranslationUnitCodec()),
    new \LingoWP\Resolution\PageOwnedHashes(),
    $langRegistry
);
$resolverRef = new ReflectionClass($resolver);
$resolverRef->getProperty('active')->setAccessible(true);
$resolverRef->getProperty('active')->setValue($resolver, true);
$resolverRef->getProperty('lang')->setAccessible(true);
$resolverRef->getProperty('lang')->setValue($resolver, $lang);

$rendered = $resolver->termMetadata(null, $termId, 'rank_math_facebook_title', true);
test_ok('rank_math_facebook_title renders translated', ($rendered[0] ?? null) === 'LINGOWP_TEST ZQSEOIT titre facebook traduit');

test_done();

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

test_done();

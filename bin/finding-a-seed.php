<?php

/**
 * Finding (A) — seed the fixture. WRITES REAL DATA, does NOT roll back.
 * Run: wp eval-file bin/finding-a-seed.php
 *
 * Creates a post with three paragraph blocks and translates all three, so the
 * next step (editing paragraph 2 in the block editor) can be observed in the UI.
 *
 * Deliberately not in tests/db/ and deliberately not named *_it.php: the
 * integration checks there all roll back, and this one must not.
 *
 * Re-runnable — it deletes and recreates its own fixture post.
 */

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Database\Repository\WpDbBoardQueries;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run through: wp eval-file bin/finding-a-seed.php\n");
    exit(1);
}

global $wpdb;

if (!defined('DB_NAME') || DB_NAME !== 'local') {
    fwrite(STDERR, "REFUSING: DB_NAME is not 'local'. This script writes real data.\n");
    exit(1);
}

$registry = new OptionLanguageRegistry();
$targets  = $registry->getTargetLanguages();

if ($targets === []) {
    fwrite(STDERR,
        "No target language is enabled.\n" .
        "Enable one in the LingoWP settings screen first, then re-run.\n");
    exit(1);
}

$lang    = (string) $targets[0];
$default = $registry->getDefaultLanguage();

echo "source language: {$default}\n";
echo "target language: {$lang}\n\n";

// ── Fixture ─────────────────────────────────────────────────────────────────

const TEST_FIXTURE_SLUG = 'lingowp-test-finding-a';

$existing = get_page_by_path(TEST_FIXTURE_SLUG, OBJECT, 'post');
if ($existing) {
    wp_delete_post($existing->ID, true);
    echo "removed previous fixture post\n";
}

$content =
    '<!-- wp:paragraph --><p>Alpha is the first paragraph of the fixture.</p><!-- /wp:paragraph -->' .
    '<!-- wp:paragraph --><p>Beta is the second paragraph of the fixture.</p><!-- /wp:paragraph -->' .
    '<!-- wp:paragraph --><p>Gamma is the third paragraph of the fixture.</p><!-- /wp:paragraph -->';

// wp_insert_post fires wp_after_insert_post, so discovery runs for real here —
// the same path a human editing in wp-admin takes.
$postId = wp_insert_post([
    'post_title'   => 'LINGOWP_TEST Finding A',
    'post_name'    => TEST_FIXTURE_SLUG,
    'post_content' => $content,
    'post_status'  => 'publish',
    'post_type'    => 'post',
], true);

if (is_wp_error($postId)) {
    fwrite(STDERR, 'Could not create the fixture post: ' . $postId->get_error_message() . "\n");
    exit(1);
}

echo "created post {$postId}\n";

// ── Verify discovery split it into three units ──────────────────────────────

$parent = TranslationMemorySchema::parentTables()['post'];

$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id, original_text FROM {$parent}
     WHERE post_id = %d AND field = 'post_content' AND status = %d
     ORDER BY id",
    $postId,
    TranslationStatus::SOURCE_DISCOVERED
), ARRAY_A);

$count = count((array) $rows);
echo "discovered post_content units: {$count}\n";

if ($count < 3) {
    fwrite(STDERR,
        "\nExpected 3 units. Discovery may not have run on insert.\n" .
        "Check that the plugin is active and SourceLifecycleRefresher is registered.\n");
    exit(1);
}

// ── Translate every unit ────────────────────────────────────────────────────

$repo = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));

foreach ($rows as $i => $row) {
    $n = $i + 1;
    $repo->saveTranslation(
        SourceRef::make('post', (int) $row['id']),
        $lang,
        "[{$lang}] traduction du paragraphe {$n}",
        TranslationStatus::REVIEWED,
        null
    );
    echo "  translated unit {$n}: " . mb_substr((string) $row['original_text'], 0, 48) . "…\n";
}

// Also translate the title, so the page is obviously in the target language.
$titleRow = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$parent} WHERE post_id = %d AND field = 'post_title' AND status = %d",
    $postId,
    TranslationStatus::SOURCE_DISCOVERED
));
if ($titleRow) {
    $repo->saveTranslation(SourceRef::make('post', (int) $titleRow), $lang, "[{$lang}] Titre LINGOWP_TEST", TranslationStatus::REVIEWED, null);
}

// ── What to do next ─────────────────────────────────────────────────────────

$permalink = get_permalink($postId);
$translated = str_replace(home_url('/'), home_url('/') . $lang . '/', $permalink);

echo "\n";
echo "─────────────────────────────────────────────────────────────\n";
echo "NEXT STEPS\n";
echo "─────────────────────────────────────────────────────────────\n";
echo "1. Open the TRANSLATED page and confirm all three paragraphs are\n";
echo "   in {$lang}:\n";
echo "     {$translated}\n\n";
echo "2. Record the 'before' state:\n";
echo "     wp eval-file bin/finding-a-report.php\n\n";
echo "3. Edit the post in wp-admin and change ONLY the SECOND paragraph\n";
echo "   (the 'Beta' one). Update.\n";
echo "     " . admin_url('post.php?post=' . $postId . '&action=edit') . "\n\n";
echo "4. Reload the translated page. EXPECTED, if finding (A) holds:\n";
echo "     - paragraphs 1 and 3 still in {$lang}\n";
echo "     - paragraph 2 back in {$default}, untranslated\n";
echo "     - and NOT flagged for review anywhere\n\n";
echo "5. Record the 'after' state and compare:\n";
echo "     wp eval-file bin/finding-a-report.php\n\n";
echo "6. Check the admin board for this post — paragraph 2 should read as\n";
echo "   Untranslated, NOT as Needs review. That absence is the finding.\n";
echo "─────────────────────────────────────────────────────────────\n";

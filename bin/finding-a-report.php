<?php

/**
 * Finding (A) — report the fixture's DB state. READ ONLY.
 * Run: wp eval-file bin/finding-a-report.php
 *
 * Run once before editing paragraph 2 and once after. The difference between
 * the two runs is the evidence for or against finding (A).
 */

use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run through: wp eval-file bin/finding-a-report.php\n");
    exit(1);
}

global $wpdb;

$post = get_page_by_path('lingowp-test-finding-a', OBJECT, 'post');
if (!$post) {
    fwrite(STDERR, "Fixture post not found. Run bin/finding-a-seed.php first.\n");
    exit(1);
}

$registry = new OptionLanguageRegistry();
$targets  = $registry->getTargetLanguages();
$lang     = $targets === [] ? '' : (string) $targets[0];

if ($lang === '') {
    fwrite(STDERR, "No target language enabled.\n");
    exit(1);
}

$parent = TranslationMemorySchema::parentTables()['post'];
$child  = $parent . '_translation';

$statusName = static function ($s): string {
    if ($s === null) {
        return 'NO TRANSLATION';
    }
    return match ((int) $s) {
        TranslationStatus::MACHINE      => 'machine (1)',
        TranslationStatus::REVIEWED     => 'reviewed (2)',
        TranslationStatus::NEEDS_REVIEW => 'NEEDS_REVIEW (-1)',
        default                         => 'status ' . (int) $s,
    };
};

$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT p.id, p.original_text, LOWER(HEX(p.original_hash)) AS h,
            t.translated_text, t.status
     FROM {$parent} p
     LEFT JOIN {$child} t
            ON t.original_hash = p.original_hash AND t.lang_code = %s
     WHERE p.post_id = %d AND p.field = 'post_content' AND p.status = %d
     ORDER BY p.id",
    $lang,
    $post->ID,
    TranslationStatus::SOURCE_DISCOVERED
), ARRAY_A);

echo "post {$post->ID} — post_content units, language '{$lang}'\n";
echo str_repeat('─', 78) . "\n";

$translated = 0;
$missing    = 0;
$review     = 0;

foreach ((array) $rows as $i => $r) {
    $n = $i + 1;
    echo sprintf("unit %d  [row %d]  hash %s…\n", $n, (int) $r['id'], substr((string) $r['h'], 0, 12));
    echo "  source:      " . mb_substr((string) $r['original_text'], 0, 60) . "\n";
    echo "  translation: " . ($r['translated_text'] === null
            ? '(none)'
            : mb_substr((string) $r['translated_text'], 0, 60)) . "\n";
    echo "  status:      " . $statusName($r['status']) . "\n\n";

    if ($r['translated_text'] === null) {
        $missing++;
    } else {
        $translated++;
        if ((int) $r['status'] === TranslationStatus::NEEDS_REVIEW) {
            $review++;
        }
    }
}

// Orphaned children: translations whose parent no longer exists. After the edit
// this is where paragraph 2's old translation ends up — stranded until the sweep.
$orphans = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$child} c
     WHERE c.lang_code = %s
       AND NOT EXISTS (SELECT 1 FROM {$parent} p WHERE p.original_hash = c.original_hash)",
    $lang
));

echo str_repeat('─', 78) . "\n";
echo "units: " . count((array) $rows)
   . " | translated: {$translated} | untranslated: {$missing} | needs_review: {$review}\n";
echo "orphaned {$lang} children across post: {$orphans}\n";
echo str_repeat('─', 78) . "\n";

if ($missing === 0 && $review === 0) {
    echo "READING: baseline — every unit translated, nothing flagged.\n";
} elseif ($missing > 0 && $review === 0) {
    echo "READING: finding (A) CONFIRMED — an edited unit lost its translation\n";
    echo "         outright. No carry-forward, nothing queued for review, and the\n";
    echo "         old translation is now one of the orphans counted above.\n";
} elseif ($review > 0 && $missing === 0) {
    echo "READING: carry-forward FIRED — the edited unit kept its translation and\n";
    echo "         is flagged needs_review. This is the expected post-fix state.\n";
    echo "         (The orphan counted above is the old hash's child, copied not\n";
    echo "          moved, and is reclaimed by OrphanTranslationSweep.)\n";
} else {
    echo "READING: mixed. Compare unit-by-unit against the 'before' run.\n";
}

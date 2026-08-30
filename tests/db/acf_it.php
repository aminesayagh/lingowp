<?php

/**
 * lingowp-acf add-on coverage — real ACF field groups registered locally
 * (acf_add_local_field_group(), request-scoped, no DB writes of their own),
 * then TermFieldKeyFilter is checked against them directly, plus one
 * end-to-end pass through core's own term-meta discovery.
 * Run: wp eval-file tests/db/acf_it.php
 *
 * TEMPORARY HOME, by request (see lingowp_addon_test_consolidation_pref
 * memory): exercises the separate lingowp-acf add-on's classes
 * (LingoWPAcf\*), not core — kept here so it runs via one `composer test:db`
 * during active development. Move back to lingowp-acf/tests/ once the add-on
 * stabilizes.
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Shared\Source\TranslatableTermMetaKeys;
use LingoWPAcf\ProseFieldTypes;
use LingoWPAcf\TermFieldKeyFilter;

global $wpdb;

test_guard();

if (!class_exists('ACF') || !function_exists('acf_add_local_field_group')) {
    fwrite(STDERR, "SKIPPED: ACF is not active; this suite needs it.\n");
    exit(0);
}
if (!class_exists('LingoWPAcf\\TermFieldKeyFilter')) {
    fwrite(STDERR, "SKIPPED: lingowp-acf add-on is not active.\n");
    exit(0);
}

test_begin();

// ── fixtures: local (request-scoped) ACF field groups, registered before any
// addKeys() call — TermFieldKeyFilter memoizes per request, so every fixture
// must exist before the first read. ──────────────────────────────────────────

acf_add_local_field_group([
    'key'    => 'group_lingowp_it_prose',
    'title'  => 'LingoWP IT — prose types',
    'fields' => [
        ['key' => 'field_lingowp_it_text',     'name' => 'lingowp_it_text',     'label' => 'Text',     'type' => 'text'],
        ['key' => 'field_lingowp_it_textarea', 'name' => 'lingowp_it_textarea', 'label' => 'Textarea', 'type' => 'textarea'],
        ['key' => 'field_lingowp_it_wysiwyg',  'name' => 'lingowp_it_wysiwyg',  'label' => 'WYSIWYG',  'type' => 'wysiwyg'],
        ['key' => 'field_lingowp_it_number',   'name' => 'lingowp_it_number',   'label' => 'Number',   'type' => 'number'],
        ['key' => 'field_lingowp_it_select',   'name' => 'lingowp_it_select',   'label' => 'Select',   'type' => 'select'],
    ],
    'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]],
]);

acf_add_local_field_group([
    'key'    => 'group_lingowp_it_containers',
    'title'  => 'LingoWP IT — containers',
    'fields' => [
        [
            'key' => 'field_lingowp_it_repeater_prose', 'name' => 'lingowp_it_repeater_prose', 'label' => 'Repeater (prose)', 'type' => 'repeater',
            'sub_fields' => [
                ['key' => 'field_lingowp_it_repeater_prose_sub', 'name' => 'sub_wysiwyg', 'label' => 'Sub', 'type' => 'wysiwyg'],
            ],
        ],
        [
            'key' => 'field_lingowp_it_repeater_flat', 'name' => 'lingowp_it_repeater_flat', 'label' => 'Repeater (no prose)', 'type' => 'repeater',
            'sub_fields' => [
                ['key' => 'field_lingowp_it_repeater_flat_sub', 'name' => 'sub_number', 'label' => 'Sub', 'type' => 'number'],
            ],
        ],
        [
            'key' => 'field_lingowp_it_group_prose', 'name' => 'lingowp_it_group_prose', 'label' => 'Group (prose)', 'type' => 'group',
            'sub_fields' => [
                ['key' => 'field_lingowp_it_group_prose_sub', 'name' => 'sub_text', 'label' => 'Sub', 'type' => 'text'],
            ],
        ],
        [
            'key' => 'field_lingowp_it_flex_prose', 'name' => 'lingowp_it_flex_prose', 'label' => 'Flexible Content (prose)', 'type' => 'flexible_content',
            'layouts' => [
                'layout_lingowp_it_flex_layout' => [
                    'key'        => 'layout_lingowp_it_flex_layout',
                    'name'       => 'a_layout',
                    'sub_fields' => [
                        ['key' => 'field_lingowp_it_flex_prose_sub', 'name' => 'layout_textarea', 'label' => 'Sub', 'type' => 'textarea'],
                    ],
                ],
            ],
        ],
    ],
    'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]],
]);

// Same prose field type, wrong location (targets a post type, not a taxonomy
// term) — must never surface. Proves locatesOnTaxonomy() actually gates.
acf_add_local_field_group([
    'key'    => 'group_lingowp_it_wrong_location',
    'title'  => 'LingoWP IT — wrong location',
    'fields' => [
        ['key' => 'field_lingowp_it_wrong_location_text', 'name' => 'lingowp_it_wrong_location_text', 'label' => 'Text', 'type' => 'text'],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
]);

// ── 1. ProseFieldTypes: pure predicate checks ────────────────────────────────

test_section('ProseFieldTypes:');

test_ok('text is prose', ProseFieldTypes::isProse('text'));
test_ok('textarea is prose', ProseFieldTypes::isProse('textarea'));
test_ok('wysiwyg is prose', ProseFieldTypes::isProse('wysiwyg'));
test_ok('number is not prose', !ProseFieldTypes::isProse('number'));
test_ok('repeater is a container, not prose itself', ProseFieldTypes::isContainer('repeater') && !ProseFieldTypes::isProse('repeater'));
test_ok('group and flexible_content are containers', ProseFieldTypes::isContainer('group') && ProseFieldTypes::isContainer('flexible_content'));

// ── 2. TermFieldKeyFilter: real ACF introspection ────────────────────────────

test_section('TermFieldKeyFilter against real ACF field groups:');

$keys = TermFieldKeyFilter::addKeys(['already_here']);

test_ok('keeps an already-present key', in_array('already_here', $keys, true));
test_ok('includes a plain text field', in_array('lingowp_it_text', $keys, true));
test_ok('includes a textarea field', in_array('lingowp_it_textarea', $keys, true));
test_ok('includes a wysiwyg field', in_array('lingowp_it_wysiwyg', $keys, true));
test_ok('excludes a number field', !in_array('lingowp_it_number', $keys, true));
test_ok('excludes a select field', !in_array('lingowp_it_select', $keys, true));
test_ok('includes a repeater whose sub-field is prose', in_array('lingowp_it_repeater_prose', $keys, true));
test_ok('excludes a repeater whose only sub-field is not prose', !in_array('lingowp_it_repeater_flat', $keys, true));
test_ok('includes a group whose sub-field is prose', in_array('lingowp_it_group_prose', $keys, true));
test_ok('includes a flexible_content whose layout sub-field is prose', in_array('lingowp_it_flex_prose', $keys, true));
test_ok('excludes a prose field on a field group not located on a taxonomy', !in_array('lingowp_it_wrong_location_text', $keys, true));

$dedupedKeys = TermFieldKeyFilter::addKeys($keys);
test_ok('re-applying the filter does not duplicate keys', count($dedupedKeys) === count(array_unique($dedupedKeys)));

// ── 3. end-to-end: a discovered key is picked up by core's own term-meta scan ─

test_section('end-to-end through core term-meta discovery:');

TermFieldKeyFilter::register();
test_ok('the filter is now wired into the term-meta allow-list', in_array('lingowp_it_text', TranslatableTermMetaKeys::keys(), true));

$termId = test_insert($wpdb->terms, [
    'name'       => 'LingoWP IT ACF term',
    'slug'       => 'lingowp-it-acf-term-' . wp_generate_password(6, false),
    'term_group' => 0,
]);
test_insert($wpdb->term_taxonomy, [
    'term_id'     => $termId,
    'taxonomy'    => 'category',
    'description' => '',
    'parent'      => 0,
    'count'       => 0,
]);

$sentinel = 'LINGOWP_TEST ZQACFIT term field sentinel.';
update_term_meta($termId, 'lingowp_it_text', $sentinel);

$scanner = new \LingoWP\Extraction\Structured\StructuredSourceScanner(
    \LingoWP\Database\Repository\WpDbSourceRepository::instance(),
    new \LingoWP\Shared\Parsing\HtmlUnitExtractor(new \LingoWP\Shared\Parsing\TranslationUnitCodec())
);
$scanner->refreshTermMeta($termId);

$TMP = TranslationMemorySchema::parentTables()['term_meta'];
$found = test_var(
    "SELECT id FROM {$TMP} WHERE term_id = %d AND meta_key_hash = UNHEX(%s) AND original_text = %s",
    $termId, bin2hex(hash('sha256', 'lingowp_it_text', true)), $sentinel
);
test_ok('an ACF-discovered key is found by core\'s term-meta scanner', $found !== null);

test_done();

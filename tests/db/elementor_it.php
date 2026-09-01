<?php

/**
 * lingowp-elementor add-on coverage — mirrors the manual wp-cli verification
 * run against a real Elementor page during development.
 * Run: wp eval-file tests/db/elementor_it.php
 *
 * TEMPORARY HOME, by request: this exercises the separate lingowp-elementor
 * add-on plugin's classes (LingoWPElementor\*), not core — kept here for now
 * so it runs via one `composer test:db` during active development, same
 * precedent as woocommerce_it.php/acf_it.php (see
 * lingowp_addon_test_consolidation_pref memory). Move back to
 * lingowp-elementor/tests/ once the add-on stabilizes.
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Resolution\StructuredRenderResolver;
use LingoWP\Resolution\RequestContext;
use LingoWP\Resolution\PageOwnedHashes;
use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Shared\Parsing\HtmlUnitExtractor;
use LingoWP\Shared\Parsing\TranslationUnitCodec;
use LingoWP\Shared\Text\TranslationKey;
use LingoWPElementor\ElementorContentSchema;
use LingoWPElementor\ElementorContentWalker;

global $wpdb;

test_guard();

if (!class_exists('Elementor\Plugin')) {
    fwrite(STDERR, "SKIPPED: Elementor is not active; this suite needs it.\n");
    exit(0);
}
if (!class_exists('LingoWPElementor\\ElementorContentWalker')) {
    fwrite(STDERR, "SKIPPED: lingowp-elementor add-on is not active.\n");
    exit(0);
}

test_begin();

$parents = TranslationMemorySchema::parentTables();
$PP      = $parents['post'];
$lang    = 'fr_FR';

$admin = get_users(['role' => 'administrator', 'number' => 1]);
test_ok('fixture: an administrator exists to own the test save', count($admin) > 0);
wp_set_current_user($admin[0]->ID);

// ── 1. ElementorContentSchema — the content-detection interface itself ──────

test_section('ElementorContentSchema (content-field detection):');

$headingPaths = ElementorContentSchema::forWidgetType('heading');
test_ok('heading: "title" detected as content', array_key_exists('title', $headingPaths), json_encode($headingPaths));
test_ok('heading: "title" carries its real control type (textarea)', ($headingPaths['title'] ?? null) === 'textarea');
test_ok('heading: "link" (url) NOT detected as content', !array_key_exists('link', $headingPaths));
test_ok('heading: no common/advanced control leaks in ("_element_id" etc.)', !array_key_exists('_element_id', $headingPaths) && !array_key_exists('_css_classes', $headingPaths));

$textEditorPaths = ElementorContentSchema::forWidgetType('text-editor');
test_ok('text-editor: "editor" detected as content, typed "wysiwyg"', ($textEditorPaths['editor'] ?? null) === 'wysiwyg');

$testimonialPaths = ElementorContentSchema::forWidgetType('testimonial');
test_ok('testimonial: all 3 own text fields detected automatically', count(array_intersect(['testimonial_content', 'testimonial_name', 'testimonial_job'], array_keys($testimonialPaths))) === 3, json_encode($testimonialPaths));

$iconListPaths = ElementorContentSchema::forWidgetType('icon-list');
test_ok('icon-list: repeater recursion finds "icon_list.text"', array_key_exists('icon_list.text', $iconListPaths), json_encode($iconListPaths));
test_ok('icon-list: repeater\'s own "link"/"selected_icon" fields excluded', !array_key_exists('icon_list.link', $iconListPaths) && !array_key_exists('icon_list.selected_icon', $iconListPaths));

test_ok('unregistered widget type returns [] (no error)', ElementorContentSchema::forWidgetType('lingowp-test-does-not-exist') === []);

// ── 2. Discovery: a real Document::save() with 4 widget types ───────────────

test_section('discovery via a real Elementor Document::save():');

$pageId = wp_insert_post([
    'post_title'  => 'LINGOWP_TEST elementor fixture',
    'post_status' => 'publish',
    'post_type'   => 'page',
]);
test_ok('fixture page created', $pageId > 0);
update_post_meta($pageId, '_elementor_edit_mode', 'builder');

$document = \Elementor\Plugin::$instance->documents->get($pageId);
test_ok('Elementor document resolved for the fixture page', $document instanceof \Elementor\Core\Base\Document);

$elementorData = [
    'elements' => [
        [
            'id' => 'aaaa1001', 'elType' => 'container', 'settings' => [],
            'elements' => [
                ['id' => 'bbbb1001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'LINGOWP_TEST Elementor Heading'], 'elements' => []],
                ['id' => 'bbbb1002', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => ['editor' => '<p>LINGOWP_TEST elementor editor paragraph.</p>'], 'elements' => []],
                ['id' => 'bbbb1003', 'elType' => 'widget', 'widgetType' => 'icon-list', 'settings' => ['icon_list' => [
                    ['text' => 'LINGOWP_TEST Row One'],
                    ['text' => 'LINGOWP_TEST Row Two'],
                ]], 'elements' => []],
                ['id' => 'bbbb1004', 'elType' => 'widget', 'widgetType' => 'testimonial', 'settings' => [
                    'testimonial_content' => 'LINGOWP_TEST Testimonial body.',
                    'testimonial_name'    => 'LINGOWP_TEST Jane Doe',
                    'testimonial_job'     => 'LINGOWP_TEST CEO',
                ], 'elements' => []],
                // A dynamically-bound field: the literal value is empty, the real
                // reference lives in settings['__dynamic__']['title'] — must NOT
                // be discovered as static prose (see ElementorContentWalker's own
                // class doc: Elementor stores dynamic bindings in this sibling
                // key, confirmed against Controls_Stack::parse_dynamic_settings(),
                // not generally inline in the field's own value).
                ['id' => 'bbbb1005', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [
                    'title'        => '',
                    '__dynamic__'  => ['title' => '[elementor-tag id="x" name="post-title" settings="[]"]'],
                ], 'elements' => []],
                // A plain "text"/"textarea" control can ALSO carry inline HTML —
                // Elementor's own Heading::render() runs the title through
                // wp_kses_post() before output, so a <strong> here is allowed
                // through, same as a WYSIWYG field. Regression fixture for the
                // second review round's finding: "text/textarea never has
                // markup" was a false assumption, not a real distinction.
                ['id' => 'bbbb1006', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'LINGOWP_TEST Bold <strong>Word</strong>.'], 'elements' => []],
            ],
        ],
    ],
    'settings' => [],
];
$saved = $document->save($elementorData);
test_ok('Document::save() succeeded', $saved === true);

// Isolated, direct test of the walker's OWN return value — bypasses the
// pre-existing post_content-mirror discovery entirely, so this genuinely
// proves the walker itself produces matching records rather than riding on
// the mirror finding the same text independently (the exact gap a review
// found in this test file's first version: the DB-level assertions below
// alone don't distinguish which mechanism actually discovered a given row).
$walkerRecords = ElementorContentWalker::extract($pageId);
$walkerTexts   = array_map(static fn($r) => $r->originalText(), $walkerRecords);
test_ok('walker itself (not the mirror) discovers the heading title', in_array('LINGOWP_TEST Elementor Heading', $walkerTexts, true), json_encode($walkerTexts));
test_ok('walker itself discovers the WYSIWYG text tag-free, matching the real per-leaf render hash', in_array('LINGOWP_TEST elementor editor paragraph.', $walkerTexts, true) && !in_array('<p>LINGOWP_TEST elementor editor paragraph.</p>', $walkerTexts, true));
test_ok('walker itself discovers both icon-list rows', in_array('LINGOWP_TEST Row One', $walkerTexts, true) && in_array('LINGOWP_TEST Row Two', $walkerTexts, true));
test_ok('walker itself splits inline HTML in a "text" control the same way (codec placeholder, no literal <strong>)', in_array('LINGOWP_TEST Bold {1}Word{/1}.', $walkerTexts, true), json_encode($walkerTexts));

$sources = $wpdb->get_col($wpdb->prepare("SELECT original_text FROM {$PP} WHERE post_id = %d AND field = 'post_content'", $pageId));
test_ok('heading title discovered', in_array('LINGOWP_TEST Elementor Heading', $sources, true), json_encode($sources));
test_ok('text-editor content discovered', in_array('LINGOWP_TEST elementor editor paragraph.', $sources, true));
// Regression guard for a real bug caught in review before shipping: an
// earlier version stored the WYSIWYG field's raw HTML (with its wrapping
// <p> tag) as one opaque unit — a hash the real per-leaf render never
// produces, so it was silently dead data. Confirms the fix: only the
// correctly split, tag-free leaf text is discovered now.
test_ok('WYSIWYG field is split like ordinary post_content, not stored with its wrapping tag', !in_array('<p>LINGOWP_TEST elementor editor paragraph.</p>', $sources, true), json_encode($sources));
test_ok('icon-list rows discovered on the initial save (not just correctly pruned later)', in_array('LINGOWP_TEST Row One', $sources, true) && in_array('LINGOWP_TEST Row Two', $sources, true));
test_ok('testimonial: all 3 fields discovered', in_array('LINGOWP_TEST Testimonial body.', $sources, true) && in_array('LINGOWP_TEST Jane Doe', $sources, true) && in_array('LINGOWP_TEST CEO', $sources, true));
test_ok('dynamically-bound field NOT discovered as static prose', !in_array('[elementor-tag id="x" name="post-title" settings="[]"]', $sources, true));
test_ok('inline HTML in a "text" control (Heading title) is split, not stored with a literal <strong> tag', !in_array('LINGOWP_TEST Bold <strong>Word</strong>.', $sources, true), json_encode($sources));

// The regression guard for this session's own core fix: reconcilePost() deletes
// anything in its (post_id) scope not present in the records it's handed, so a
// caller that reconciled ONLY Elementor-derived records (instead of appending
// via the lingowp_post_source_records filter, as this add-on actually does)
// would wipe post_title on this very save. (Not testable by mixing raw
// post_content HTML with Elementor content on ONE post — confirmed live that
// Elementor's own save_plain_text() regenerates post_content entirely from
// _elementor_data on every save, by Elementor's own design, so post_title is
// the correct, realistic sibling-record proof here.)
$titleAfter = $wpdb->get_var($wpdb->prepare("SELECT original_text FROM {$PP} WHERE post_id = %d AND field = 'post_title'", $pageId));
test_ok('reconcile-safety: post_title survives the SAME reconcile as the Elementor-derived records', $titleAfter === 'LINGOWP_TEST elementor fixture', "got: {$titleAfter}");

// ── 3. Render: translations reach the real Elementor-rendered output ────────

test_section('render (real Elementor-rendered the_content + StructuredRenderResolver):');

$translations = [
    'LINGOWP_TEST Elementor Heading'       => 'LINGOWP_TEST Titre Traduit',
    'LINGOWP_TEST elementor editor paragraph.' => 'LINGOWP_TEST paragraphe traduit.',
    'LINGOWP_TEST Testimonial body.'       => 'LINGOWP_TEST corps traduit.',
    'LINGOWP_TEST Jane Doe'                => 'LINGOWP_TEST Jeanne Dupont',
    'LINGOWP_TEST CEO'                     => 'LINGOWP_TEST PDG',
];
// Kept separate from the loop below: its translated text carries codec
// placeholder syntax ({1}..{/1}), which render() decodes back into the real
// <strong> tag — the loop's plain str_contains() check is for the OTHERS,
// where the stored translation already IS the final rendered text verbatim.
$inlineHtmlOriginal    = 'LINGOWP_TEST Bold {1}Word{/1}.';
$inlineHtmlTranslated  = 'LINGOWP_TEST Gras {1}Mot{/1}.';
$translations[$inlineHtmlOriginal] = $inlineHtmlTranslated;
foreach ($translations as $original => $translated) {
    $hash = TranslationKey::currentHash($original);
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$PP}_translation (original_hash, lang_code, translated_text, status, created_at, updated_at) VALUES (UNHEX(%s), %s, %s, 2, NOW(), NOW())",
        bin2hex($hash), $lang, $translated
    ));
}

$post = get_post($pageId);
$GLOBALS['post'] = $post;
setup_postdata($post);
$GLOBALS['wp_the_query'] = $wp_query = new WP_Query(['p' => $pageId, 'post_type' => 'page']);
$wp_query->is_singular       = true;
$wp_query->is_page           = true;
$wp_query->queried_object    = $post;
$wp_query->queried_object_id = $pageId;
$wp_query->in_the_loop       = true;

$rendered = apply_filters('the_content', get_the_content(null, false, $post));

$repo     = WpDbSourceRepository::instance();
$resolver = new StructuredRenderResolver(
    new ResolveRequestLanguage($registry = new OptionLanguageRegistry()),
    new RequestContext(),
    $repo,
    new HtmlUnitExtractor(new TranslationUnitCodec()),
    new PageOwnedHashes(),
    $registry
);
$ref = new ReflectionClass($resolver);
$ref->getProperty('active')->setAccessible(true);
$ref->getProperty('active')->setValue($resolver, true);
$ref->getProperty('lang')->setAccessible(true);
$ref->getProperty('lang')->setValue($resolver, $lang);

$translatedHtml = $resolver->content($rendered);

foreach ($translations as $original => $translated) {
    if ($original === $inlineHtmlOriginal) {
        continue; // checked separately below — its stored form isn't its rendered form
    }
    test_ok("render: \"{$translated}\" appears in the real Elementor-rendered output", str_contains($translatedHtml, $translated));
}
// The inline-HTML case: render() decodes the {1}..{/1} placeholder back into
// the real <strong> tag using the SOURCE's own tag map, so the rendered
// output should show real markup around the translated word, not literal
// placeholder syntax.
test_ok('render: inline HTML in a "text" control translates with its tag correctly rebuilt', str_contains($translatedHtml, 'LINGOWP_TEST Gras <strong>Mot</strong>.'), $translatedHtml);

// ── 4. Delete/prune: removing a widget + clearing a field on re-save ────────

test_section('delete/prune on a real re-save:');

$elementorData['elements'][0]['elements'] = [
    $elementorData['elements'][0]['elements'][0], // heading
    $elementorData['elements'][0]['elements'][1], // text-editor
    // icon-list widget removed entirely
    [
        'id' => 'bbbb1004', 'elType' => 'widget', 'widgetType' => 'testimonial', 'settings' => [
            'testimonial_content' => 'LINGOWP_TEST Testimonial body.',
            'testimonial_name'    => 'LINGOWP_TEST Jane Doe',
            'testimonial_job'     => '', // cleared
        ], 'elements' => [],
    ],
];
unset($elementorData['elements'][0]['elements'][4]); // drop the dynamic-tag fixture widget too
$elementorData['elements'][0]['elements'] = array_values($elementorData['elements'][0]['elements']);

$resaved = $document->save($elementorData);
test_ok('re-save succeeded', $resaved === true);

$sourcesAfter = $wpdb->get_col($wpdb->prepare("SELECT original_text FROM {$PP} WHERE post_id = %d AND field = 'post_content'", $pageId));
test_ok('removed icon-list rows are pruned', !in_array('LINGOWP_TEST Row One', $sourcesAfter, true) && !in_array('LINGOWP_TEST Row Two', $sourcesAfter, true));
test_ok('cleared testimonial_job is pruned', !in_array('LINGOWP_TEST CEO', $sourcesAfter, true));
test_ok('untouched fields survive the re-save', in_array('LINGOWP_TEST Elementor Heading', $sourcesAfter, true) && in_array('LINGOWP_TEST Jane Doe', $sourcesAfter, true));

// Second instance of the same regression guard, across a re-save specifically
// (the case that actually matters: a save AFTER the filter's had a chance to
// run more than once) — reconcilePost() rebuilds the whole scope every time,
// so this is the sterner of the two checks.
$titleStillAfter = $wpdb->get_var($wpdb->prepare("SELECT original_text FROM {$PP} WHERE post_id = %d AND field = 'post_title'", $pageId));
test_ok('reconcile-safety: post_title still survives after a SECOND reconcile (the re-save)', $titleStillAfter === 'LINGOWP_TEST elementor fixture');

test_done();

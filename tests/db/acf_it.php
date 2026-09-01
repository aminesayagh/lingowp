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
use LingoWP\Shared\Source\TranslatableMetaKeys;
use LingoWP\Shared\Source\TranslatableTermMetaKeys;
use LingoWP\Shared\Source\Type\UserSource;
use LingoWPAcf\ProseFieldTypes;
use LingoWPAcf\TermFieldKeyFilter;
use LingoWPAcf\PostFieldKeyFilter;
use LingoWPAcf\UserFieldKeyFilter;
use LingoWPAcf\UserFieldResolver;

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

// Same prose field type, wrong location for TermFieldKeyFilter (targets a
// post type, not a taxonomy term) — must never surface there. Doubles as
// PostFieldKeyFilter's own POSITIVE case below, since it genuinely IS
// post-type-located.
acf_add_local_field_group([
    'key'    => 'group_lingowp_it_wrong_location',
    'title'  => 'LingoWP IT — wrong location',
    'fields' => [
        ['key' => 'field_lingowp_it_wrong_location_text', 'name' => 'lingowp_it_wrong_location_text', 'label' => 'Text', 'type' => 'text'],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
]);

// ── PostFieldKeyFilter fixtures — same shapes, located on a post type ───────

acf_add_local_field_group([
    'key'    => 'group_lingowp_it_post_prose',
    'title'  => 'LingoWP IT — post prose types',
    'fields' => [
        ['key' => 'field_lingowp_it_post_text',   'name' => 'lingowp_it_post_text',   'label' => 'Text',   'type' => 'text'],
        ['key' => 'field_lingowp_it_post_number', 'name' => 'lingowp_it_post_number', 'label' => 'Number', 'type' => 'number'],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
]);

acf_add_local_field_group([
    'key'    => 'group_lingowp_it_post_repeater',
    'title'  => 'LingoWP IT — post repeater',
    'fields' => [
        [
            'key' => 'field_lingowp_it_post_repeater', 'name' => 'lingowp_it_post_repeater', 'label' => 'Repeater (prose)', 'type' => 'repeater',
            'sub_fields' => [
                ['key' => 'field_lingowp_it_post_repeater_sub', 'name' => 'sub_text', 'label' => 'Sub', 'type' => 'text'],
            ],
        ],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
]);

// Same prose field type, wrong location for PostFieldKeyFilter (targets a
// taxonomy, not a post type) — the mirror-image check of the fixture above.
acf_add_local_field_group([
    'key'    => 'group_lingowp_it_wrong_location_for_post',
    'title'  => 'LingoWP IT — wrong location for post',
    'fields' => [
        ['key' => 'field_lingowp_it_wrong_location_for_post_text', 'name' => 'lingowp_it_wrong_location_for_post_text', 'label' => 'Text', 'type' => 'text'],
    ],
    'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]],
]);

// ── Link field fixture (post-level — same universal TranslatableText gate
// protects it at term-level too, not separately duplicated here) ───────────

acf_add_local_field_group([
    'key'    => 'group_lingowp_it_post_link',
    'title'  => 'LingoWP IT — post link field',
    'fields' => [
        ['key' => 'field_lingowp_it_post_link', 'name' => 'lingowp_it_post_link', 'label' => 'Link', 'type' => 'link'],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
]);

// ── UserFieldKeyFilter fixtures — scalar-only, no container/Link recursion ──

acf_add_local_field_group([
    'key'    => 'group_lingowp_it_user',
    'title'  => 'LingoWP IT — user fields',
    'fields' => [
        ['key' => 'field_lingowp_it_user_text',   'name' => 'lingowp_it_user_text',   'label' => 'Text',   'type' => 'text'],
        ['key' => 'field_lingowp_it_user_number', 'name' => 'lingowp_it_user_number', 'label' => 'Number', 'type' => 'number'],
        ['key' => 'field_lingowp_it_user_link',   'name' => 'lingowp_it_user_link',   'label' => 'Link',   'type' => 'link'],
        [
            'key' => 'field_lingowp_it_user_repeater', 'name' => 'lingowp_it_user_repeater', 'label' => 'Repeater', 'type' => 'repeater',
            'sub_fields' => [
                ['key' => 'field_lingowp_it_user_repeater_sub', 'name' => 'sub_text', 'label' => 'Sub', 'type' => 'text'],
            ],
        ],
    ],
    'location' => [[['param' => 'user_form', 'operator' => '==', 'value' => 'all']]],
]);

// A group located via 'user_role' instead of 'user_form' — ACF's other
// first-class user-profile location, also puts the field on a profile page.
acf_add_local_field_group([
    'key'    => 'group_lingowp_it_user_role',
    'title'  => 'LingoWP IT — user fields (user_role location)',
    'fields' => [
        ['key' => 'field_lingowp_it_user_role_text', 'name' => 'lingowp_it_user_role_text', 'label' => 'Text', 'type' => 'text'],
    ],
    'location' => [[['param' => 'user_role', 'operator' => '==', 'value' => 'subscriber']]],
]);

// ── 1. ProseFieldTypes: pure predicate checks ────────────────────────────────

test_section('ProseFieldTypes:');

test_ok('text is prose', ProseFieldTypes::isProse('text'));
test_ok('textarea is prose', ProseFieldTypes::isProse('textarea'));
test_ok('wysiwyg is prose', ProseFieldTypes::isProse('wysiwyg'));
test_ok('link is prose', ProseFieldTypes::isProse('link'));
test_ok('number is not prose', !ProseFieldTypes::isProse('number'));
test_ok('repeater is a container, not prose itself', ProseFieldTypes::isContainer('repeater') && !ProseFieldTypes::isProse('repeater'));
test_ok('group and flexible_content are containers', ProseFieldTypes::isContainer('group') && ProseFieldTypes::isContainer('flexible_content'));
test_ok('text/textarea/wysiwyg are scalar-safe', ProseFieldTypes::isScalarProse('text') && ProseFieldTypes::isScalarProse('textarea') && ProseFieldTypes::isScalarProse('wysiwyg'));
test_ok('link is prose but NOT scalar-safe (structured value)', ProseFieldTypes::isProse('link') && !ProseFieldTypes::isScalarProse('link'));

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

$now  = current_time('mysql');
$lang = 'fr_FR';

// ── 4. PostFieldKeyFilter: real ACF introspection ────────────────────────────

test_section('PostFieldKeyFilter against real ACF field groups:');

$postKeys = PostFieldKeyFilter::addKeys(['already_here']);

test_ok('keeps an already-present key', in_array('already_here', $postKeys, true));
test_ok('includes a plain text field', in_array('lingowp_it_post_text', $postKeys, true));
test_ok('excludes a number field', !in_array('lingowp_it_post_number', $postKeys, true));
test_ok('includes a repeater whose sub-field is prose', in_array('lingowp_it_post_repeater', $postKeys, true));
test_ok('includes a link field', in_array('lingowp_it_post_link', $postKeys, true));
test_ok('includes a prose field on a field group located on a post type', in_array('lingowp_it_wrong_location_text', $postKeys, true));
test_ok('excludes a prose field on a field group located on a taxonomy, not a post type', !in_array('lingowp_it_wrong_location_for_post_text', $postKeys, true));

$dedupedPostKeys = PostFieldKeyFilter::addKeys($postKeys);
test_ok('re-applying the filter does not duplicate keys', count($dedupedPostKeys) === count(array_unique($dedupedPostKeys)));

// ── 5. end-to-end: a discovered key is picked up by core's own post-meta scan ─

test_section('end-to-end through core post-meta discovery and render:');

PostFieldKeyFilter::register();
test_ok('the filter is now wired into the post-meta allow-list', in_array('lingowp_it_post_text', TranslatableMetaKeys::keys(), true));

$postId = test_insert($wpdb->posts, [
    'post_author'       => 1,
    'post_date'         => $now,
    'post_date_gmt'     => $now,
    'post_content'      => '',
    'post_title'        => 'LingoWP IT ACF post',
    'post_excerpt'      => '',
    'post_status'       => 'publish',
    'comment_status'    => 'closed',
    'ping_status'       => 'closed',
    'post_name'         => 'lingowp-it-acf-post-' . wp_generate_password(6, false),
    'post_modified'     => $now,
    'post_modified_gmt' => $now,
    'post_type'         => 'post',
]);

$postSentinel = 'LINGOWP_TEST ZQACFIT post field sentinel.';
update_post_meta($postId, 'lingowp_it_post_text', $postSentinel);
update_post_meta($postId, 'lingowp_it_post_repeater', [
    ['sub_text' => 'LINGOWP_TEST ZQACFIT row one'],
    ['sub_text' => 'LINGOWP_TEST ZQACFIT row two'],
]);
update_post_meta($postId, 'lingowp_it_post_link', [
    'title'  => 'LINGOWP_TEST ZQACFIT link title',
    'url'    => 'https://example.test/lingowp-it-link',
    'target' => '_blank',
]);

$scanner->refreshPostMetaKey($postId, 'lingowp_it_post_text');
$scanner->refreshPostMetaKey($postId, 'lingowp_it_post_repeater');
$scanner->refreshPostMetaKey($postId, 'lingowp_it_post_link');

$MP = TranslationMemorySchema::parentTables()['meta'];
$foundScalar = test_var(
    "SELECT id FROM {$MP} WHERE post_id = %d AND meta_key_hash = UNHEX(%s) AND context IS NULL AND original_text = %s",
    $postId, bin2hex(hash('sha256', 'lingowp_it_post_text', true)), $postSentinel
);
test_ok('an ACF-discovered scalar post field is found by core\'s post-meta scanner', $foundScalar !== null);

$linkTitleFound = test_var(
    "SELECT id FROM {$MP} WHERE post_id = %d AND meta_key_hash = UNHEX(%s) AND context = %s",
    $postId, bin2hex(hash('sha256', 'lingowp_it_post_link', true)), 'title'
);
test_ok('a link field\'s title leaf is discovered', $linkTitleFound !== null);
$linkUrlFound = test_var(
    "SELECT id FROM {$MP} WHERE post_id = %d AND meta_key_hash = UNHEX(%s) AND context = %s",
    $postId, bin2hex(hash('sha256', 'lingowp_it_post_link', true)), 'url'
);
test_ok('a link field\'s url leaf is correctly rejected (universal TranslatableText gate)', $linkUrlFound === null);

$row0Id = test_var(
    "SELECT id FROM {$MP} WHERE post_id = %d AND meta_key_hash = UNHEX(%s) AND context = %s AND original_text = %s",
    $postId, bin2hex(hash('sha256', 'lingowp_it_post_repeater', true)), '0.sub_text', 'LINGOWP_TEST ZQACFIT row one'
);
test_ok('a repeater row on a post field is found as its own leaf', $row0Id !== null);

// Render: scalar via the fast path, repeater row via the multi-leaf patch —
// same core mechanism TermFieldKeyFilter's own render already relies on.
test_insert($MP . '_translation', [
    'parent_id'       => $foundScalar,
    'lang_code'       => $lang,
    'translated_text' => 'LINGOWP_TEST ZQACFIT scalar traduit',
    'status'          => 2,
    'created_at'      => $now,
    'updated_at'      => $now,
]);
test_insert($MP . '_translation', [
    'parent_id'       => $row0Id,
    'lang_code'       => $lang,
    'translated_text' => 'LINGOWP_TEST ZQACFIT ligne un traduite',
    'status'          => 2,
    'created_at'      => $now,
    'updated_at'      => $now,
]);

$postResolver = new \LingoWP\Resolution\StructuredRenderResolver(
    new \LingoWP\Language\Application\ResolveRequestLanguage($postLangRegistry = new \LingoWP\Language\Infrastructure\OptionLanguageRegistry()),
    new \LingoWP\Resolution\RequestContext(),
    \LingoWP\Database\Repository\WpDbSourceRepository::instance(),
    new \LingoWP\Shared\Parsing\HtmlUnitExtractor(new \LingoWP\Shared\Parsing\TranslationUnitCodec()),
    new \LingoWP\Resolution\PageOwnedHashes(),
    $postLangRegistry
);
$postResolverRef = new ReflectionClass($postResolver);
$postResolverRef->getProperty('active')->setAccessible(true);
$postResolverRef->getProperty('active')->setValue($postResolver, true);
$postResolverRef->getProperty('lang')->setAccessible(true);
$postResolverRef->getProperty('lang')->setValue($postResolver, $lang);

$scalarRender = $postResolver->metadata(null, $postId, 'lingowp_it_post_text', true);
test_ok('scalar post field renders translated', ($scalarRender[0] ?? null) === 'LINGOWP_TEST ZQACFIT scalar traduit');

$repeaterRender = $postResolver->metadata(null, $postId, 'lingowp_it_post_repeater', true);
test_ok(
    'repeater row 0 renders translated, row 1 stays untranslated',
    ($repeaterRender[0][0]['sub_text'] ?? null) === 'LINGOWP_TEST ZQACFIT ligne un traduite'
        && ($repeaterRender[0][1]['sub_text'] ?? null) === 'LINGOWP_TEST ZQACFIT row two'
);

// ── 6. UserFieldKeyFilter: real ACF introspection ────────────────────────────

test_section('UserFieldKeyFilter against real ACF field groups:');

$userFieldKeys = UserFieldKeyFilter::addKeys(['already_here']);

test_ok('keeps an already-present key', in_array('already_here', $userFieldKeys, true));
test_ok('includes a plain text field', in_array('lingowp_it_user_text', $userFieldKeys, true));
test_ok('excludes a number field', !in_array('lingowp_it_user_number', $userFieldKeys, true));
test_ok('excludes a link field (structured value, no leaf-extraction on users)', !in_array('lingowp_it_user_link', $userFieldKeys, true));
test_ok('excludes a repeater (container, no leaf-extraction on users)', !in_array('lingowp_it_user_repeater', $userFieldKeys, true));
test_ok('includes a text field on a user_role-located group', in_array('lingowp_it_user_role_text', $userFieldKeys, true));

$dedupedUserKeys = UserFieldKeyFilter::addKeys($userFieldKeys);
test_ok('re-applying the filter does not duplicate keys', count($dedupedUserKeys) === count(array_unique($dedupedUserKeys)));

// ── 7. end-to-end: a discovered key is picked up by core's own user discovery,
//      and rendered via UserFieldResolver's own get_user_metadata hook ───────

test_section('end-to-end through core user discovery and UserFieldResolver render:');

UserFieldKeyFilter::register();
test_ok('the filter is now wired into the user-source allow-list', in_array('lingowp_it_user_text', UserSource::allowedFields(), true));

$userId = test_insert($wpdb->users, [
    'user_login'      => 'lingowp_it_acf_user_' . wp_generate_password(6, false),
    'user_pass'       => wp_generate_password(),
    'user_registered' => $now,
]);

$userSentinel = 'LINGOWP_TEST ZQACFIT user field sentinel.';
update_user_meta($userId, 'lingowp_it_user_text', $userSentinel);

$userScanner = new \LingoWP\Extraction\Structured\StructuredSourceScanner(
    \LingoWP\Database\Repository\WpDbSourceRepository::instance(),
    new \LingoWP\Shared\Parsing\HtmlUnitExtractor(new \LingoWP\Shared\Parsing\TranslationUnitCodec())
);
$userScanner->refreshUser($userId);

$UP = TranslationMemorySchema::parentTables()['user'];
$userRow = $wpdb->get_row($wpdb->prepare(
    "SELECT id, original_hash FROM {$UP} WHERE user_id = %d AND field = %s AND original_text = %s",
    $userId, 'lingowp_it_user_text', $userSentinel
));
test_ok('an ACF-discovered user field is found by core\'s user scanner', $userRow !== null);

test_insert($UP . '_translation', [
    'original_hash'   => $userRow->original_hash,
    'lang_code'       => $lang,
    'translated_text' => 'LINGOWP_TEST ZQACFIT champ utilisateur traduit',
    'status'          => 2,
    'created_at'      => $now,
    'updated_at'      => $now,
]);

UserFieldResolver::register();

$activationRef = new ReflectionClass(\LingoWPAcf\RequestActivation::class);
$activationRef->getProperty('context')->setAccessible(true);
$activationRef->getProperty('context')->setValue(null, new class extends \LingoWP\Resolution\RequestContext {
    public function shouldTranslate(): bool
    {
        return true;
    }
});
$activationRef->getProperty('language')->setAccessible(true);
$activationRef->getProperty('language')->setValue(null, new class ($lang) extends \LingoWP\Language\Application\ResolveRequestLanguage {
    private string $l;
    public function __construct(string $l = '')
    {
        $this->l = $l;
    }
    public function resolve(): string
    {
        return $this->l;
    }
    public function isTranslatedRequest(): bool
    {
        return true;
    }
});
if (!did_action('wp')) {
    do_action('wp');
}

$userFieldRender = UserFieldResolver::translate(null, $userId, 'lingowp_it_user_text', true);
test_ok('an ACF user field renders translated via UserFieldResolver', ($userFieldRender[0] ?? null) === 'LINGOWP_TEST ZQACFIT champ utilisateur traduit');

$unrelatedUserKey = UserFieldResolver::translate('untouched', $userId, 'lingowp_it_not_a_real_key', true);
test_ok('an unrelated user meta key is left untouched', $unrelatedUserKey === 'untouched');

test_done();

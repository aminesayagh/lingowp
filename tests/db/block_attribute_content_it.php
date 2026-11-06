<?php

/**
 * Block-settings-only content (Search block, Navigation Link, Post Terms, …) —
 * the architectural gap found in checklist Sections 5-6 and fixed same session.
 * Run: wp eval-file tests/db/block_attribute_content_it.php
 *
 * Needs WordPress's own parse_blocks()/WP_Block_Type_Registry, not pure PHP —
 * hence a wp eval-file integration test alongside section5/section6, rather
 * than a tests/split_field_check.php-style pure check.
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Database\Repository\WpDbBoardQueries;
use LingoWP\Extraction\Structured\StructuredSourceScanner;
use LingoWP\Shared\Parsing\HtmlUnitExtractor;
use LingoWP\Shared\Parsing\TranslationUnitCodec;
use LingoWP\Shared\Source\BlockContentAttributes;
use LingoWP\Shared\Text\TranslationKey;
use LingoWP\Database\TranslationMemorySchema;

global $wpdb;

test_guard();
test_begin();

$repo    = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
$scanner = new StructuredSourceScanner($repo, new HtmlUnitExtractor(new TranslationUnitCodec()));
$parents = TranslationMemorySchema::parentTables();
$PP      = $parents['post'];
$now     = current_time('mysql');

// Raw insert, like every other test in this suite — no hooks fire, so refreshPost()
// (StructuredSourceScanner's own scanning logic, not the lifecycle wiring) is called
// explicitly right after, same as section1_posts_pages_it.php/section2_taxonomies_it.php.
$makePost = static function (string $content) use ($wpdb, $now, $scanner): int {
    $id = test_insert($wpdb->posts, [
        'post_author'       => 1,
        'post_date'         => $now,
        'post_date_gmt'     => $now,
        'post_content'      => $content,
        'post_title'        => 'LINGOWP_TEST block-attr fixture',
        'post_excerpt'      => '',
        'post_status'       => 'publish',
        'comment_status'    => 'closed',
        'ping_status'       => 'closed',
        'post_name'         => 'lingowp-test-block-attr-' . wp_generate_password(8, false),
        'post_modified'     => $now,
        'post_modified_gmt' => $now,
        'post_type'         => 'post',
    ]);
    $scanner->refreshPost($id);
    return $id;
};

$contentRows = static function (int $postId) use ($wpdb, $PP): array {
    return $wpdb->get_col($wpdb->prepare(
        "SELECT original_text FROM {$PP} WHERE post_id = %d AND field = 'post_content' ORDER BY id",
        $postId
    ));
};

// ── 1. The confirmed gaps: Search / Navigation Link / Post Terms ────────────

test_section('block-settings-only content (Search, Navigation Link, Post Terms):');

$content = implode('', [
    '<!-- wp:search {"label":"LINGOWP_TEST search label","placeholder":"LINGOWP_TEST placeholder","buttonText":"LINGOWP_TEST button"} /-->',
    '<!-- wp:navigation-link {"label":"LINGOWP_TEST nav label","url":"/"} /-->',
    '<!-- wp:post-terms {"term":"category","prefix":"LINGOWP_TEST prefix: ","suffix":"LINGOWP_TEST suffix"} /-->',
]);
$id   = $makePost($content);
$rows = $contentRows($id);

test_ok('search block label/placeholder/buttonText all discovered', in_array('LINGOWP_TEST search label', $rows, true)
    && in_array('LINGOWP_TEST placeholder', $rows, true)
    && in_array('LINGOWP_TEST button', $rows, true), 'got ' . json_encode($rows));
test_ok('navigation link label discovered', in_array('LINGOWP_TEST nav label', $rows, true), 'got ' . json_encode($rows));
test_ok('post terms prefix/suffix discovered', in_array('LINGOWP_TEST prefix: ', $rows, true)
    && in_array('LINGOWP_TEST suffix', $rows, true), 'got ' . json_encode($rows));

// ── 2. Static block regression guard ─────────────────────────────────────────

test_section('static block regression guard:');

$staticId = $makePost('<!-- wp:paragraph --><p>LINGOWP_TEST static paragraph.</p><!-- /wp:paragraph -->');
test_ok('an ordinary static paragraph is unaffected',
    $contentRows($staticId) === ['LINGOWP_TEST static paragraph.']);

// ── 3. Nesting: Navigation Link inside Navigation inside Group ──────────────

test_section('nested block (3 levels deep):');

$nestedContent = '<!-- wp:group --><div class="wp-block-group"><!-- wp:navigation --><nav class="wp-block-navigation">'
    . '<!-- wp:navigation-link {"label":"LINGOWP_TEST nested nav label","url":"/"} /-->'
    . '</nav><!-- /wp:navigation --></div><!-- /wp:group -->';
$nestedId = $makePost($nestedContent);
test_ok('a nav-link 3 levels deep is still discovered',
    in_array('LINGOWP_TEST nested nav label', $contentRows($nestedId), true),
    'got ' . json_encode($contentRows($nestedId)));

// ── 4. Unregistered block name: no crash ─────────────────────────────────────

test_section('unregistered block type:');

$unknownId = $makePost('<!-- wp:lingowp-test/does-not-exist {"label":"LINGOWP_TEST should never appear"} /-->');
test_ok('an unregistered block type is silently skipped, no crash',
    $contentRows($unknownId) === []);

// ── 5. Render: dynamic block attrs resolve, static block attrs never touched ─

test_section('render (blockAttributes()):');

// Render resolves purely by hash of the CURRENT attrs value (translation()), with no
// parent-row dependency — so the translation only needs to exist in the hash-link
// child table, inserted directly by hash, matching exactly what render looks up.
$searchHash = TranslationKey::currentHash('LINGOWP_TEST render label');
$wpdb->query($wpdb->prepare(
    "INSERT IGNORE INTO {$parents['post']}_translation (original_hash, lang_code, translated_text, status, created_at, updated_at) VALUES (UNHEX(%s), %s, %s, 1, NOW(), NOW())",
    bin2hex($searchHash), 'fr', 'LINGOWP_TEST traduit'
));

$resolver = new \LingoWP\Resolution\StructuredRenderResolver(
    new \LingoWP\Language\Application\ResolveRequestLanguage($languageRegistry = new \LingoWP\Language\Infrastructure\OptionLanguageRegistry()),
    new \LingoWP\Resolution\RequestContext(),
    $repo,
    new HtmlUnitExtractor(new TranslationUnitCodec()),
    new \LingoWP\Resolution\PageOwnedHashes(),
    $languageRegistry
);
$ref = new ReflectionClass($resolver);
$ref->getProperty('active')->setAccessible(true);
$ref->getProperty('active')->setValue($resolver, true);
$ref->getProperty('lang')->setAccessible(true);
$ref->getProperty('lang')->setValue($resolver, 'fr');

$dynamicResult = $resolver->blockAttributes(['blockName' => 'core/search', 'attrs' => ['label' => 'LINGOWP_TEST render label']]);
test_ok('a dynamic block\'s content attribute resolves to its translation',
    ($dynamicResult['attrs']['label'] ?? null) === 'LINGOWP_TEST traduit', 'got ' . json_encode($dynamicResult));

$staticResult = $resolver->blockAttributes(['blockName' => 'core/paragraph', 'attrs' => ['content' => 'LINGOWP_TEST render label']]);
test_ok('a static block\'s attrs are never touched, even with a matching translation available',
    ($staticResult['attrs']['content'] ?? null) === 'LINGOWP_TEST render label', 'got ' . json_encode($staticResult));

// ── 5b. Output escaping at each filter's own return (WordPress.org review) ───

test_section('escaping (title, excerpt, block label):');

$xssHash = TranslationKey::currentHash('LINGOWP_TEST xss source');
$wpdb->query($wpdb->prepare(
    "INSERT IGNORE INTO {$parents['post']}_translation (original_hash, lang_code, translated_text, status, created_at, updated_at) VALUES (UNHEX(%s), %s, %s, 1, NOW(), NOW())",
    bin2hex($xssHash), 'fr', 'Titre <script>alert(1)</script><em onclick="x()">ok</em> & co'
));

$title = (string) $resolver->title('LINGOWP_TEST xss source', 0);
test_ok('title: a script in the translation is removed', strpos($title, '<script') === false, $title);
test_ok('title: an event attribute is removed', strpos($title, 'onclick') === false, $title);
test_ok('title: allowed inline markup and the text survive', strpos($title, '<em>ok</em>') !== false && strpos($title, 'Titre') === 0, $title);

$excerpt = (string) $resolver->excerpt('LINGOWP_TEST xss source', null);
test_ok('excerpt: same escaping', strpos($excerpt, '<script') === false && strpos($excerpt, '<em>ok</em>') !== false, $excerpt);

$label = $resolver->blockAttributes(['blockName' => 'core/search', 'attrs' => ['label' => 'LINGOWP_TEST xss source']]);
test_ok('block label: same escaping', strpos((string) ($label['attrs']['label'] ?? ''), '<script') === false, json_encode($label));

test_ok('an untranslated title comes back exactly as given',
    $resolver->title('LINGOWP_TEST <b>untranslated</b> title', 0) === 'LINGOWP_TEST <b>untranslated</b> title');

// Content: only the translated text is escaped; the post's own markup (an
// embed's iframe here) is kept, which wp_kses_post() over the whole would strip.
$contentHash = TranslationKey::currentHash('LINGOWP_TEST content source');
$wpdb->query($wpdb->prepare(
    "INSERT IGNORE INTO {$parents['post']}_translation (original_hash, lang_code, translated_text, status, created_at, updated_at) VALUES (UNHEX(%s), %s, %s, 1, NOW(), NOW())",
    bin2hex($contentHash), 'fr', 'Bonjour <script>alert(1)</script>'
));
$content = (string) $resolver->content('<p>LINGOWP_TEST content source</p><iframe src="https://www.youtube.com/embed/x" title="video"></iframe>');
test_ok('content: a script in the translation is shown as text, never as a tag', strpos($content, '<script') === false && strpos($content, '&lt;script') !== false, $content);
test_ok('content: the translated text is there', strpos($content, 'Bonjour') !== false, $content);
test_ok('content: the post\'s own embed iframe is kept', strpos($content, '<iframe') !== false, $content);

// ── 6. Schema helper sanity ───────────────────────────────────────────────────

test_section('BlockContentAttributes:');

test_ok('core/search is dynamic', BlockContentAttributes::isDynamic('core/search') === true);
test_ok('core/paragraph is not dynamic', BlockContentAttributes::isDynamic('core/paragraph') === false);
test_ok('an unregistered block type is not dynamic', BlockContentAttributes::isDynamic('lingowp-test/nope') === false);
test_ok('core/search content keys include label/placeholder/buttonText',
    array_diff(['label', 'placeholder', 'buttonText'], BlockContentAttributes::contentKeys('core/search')) === []);

test_done();

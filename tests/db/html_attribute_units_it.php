<?php

/**
 * Text inside HTML attributes (alt, title, aria-label, placeholder, button
 * value) — each value is its own hash-keyed unit, in post content and in
 * template HTML alike.
 * Run: wp eval-file tests/db/html_attribute_units_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\Repository\WpDbBoardQueries;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Extraction\Structured\StructuredSourceScanner;
use LingoWP\Shared\Parsing\HtmlUnitExtractor;
use LingoWP\Shared\Parsing\TranslationUnitCodec;
use LingoWP\Shared\Text\TranslationKey;

test_guard();
test_begin();

$extractor = new HtmlUnitExtractor(new TranslationUnitCodec());

// ── 1. Extractor: which attributes become units ─────────────────────────────

test_section('extractor: allow-listed attributes become units:');

$units = $extractor->units(implode('', [
    '<figure><img src="/a.jpg" alt="A dog on the beach"></figure>',
    '<p><a href="/x" title="Read the full story">More</a></p>',
    '<button aria-label="Close the menu">×</button>',
    '<form><input type="search" placeholder="Search the shop">',
    '<input type="submit" value="Send message">',
    '<input type="image" src="/go.png" alt="Go there">',
    '<textarea placeholder="Your comment here"></textarea></form>',
    '<iframe src="https://example.com/v" title="Product video"></iframe>',
]));

foreach (['A dog on the beach', 'Read the full story', 'Close the menu', 'Search the shop',
    'Send message', 'Go there', 'Your comment here', 'Product video'] as $expected) {
    test_ok("'{$expected}' is a unit", in_array($expected, $units, true), 'got ' . json_encode($units));
}
test_ok('the paragraph text is still its own unit', in_array('More', $units, true), 'got ' . json_encode($units));

test_section('extractor: machine values and non-text attributes are skipped:');

$skipped = $extractor->units(implode('', [
    '<img src="/a.jpg" alt="IMG_1234.jpg">',
    '<img src="/b.jpg" alt="https://example.com/b.jpg">',
    '<img src="/c.jpg" alt="">',
    '<img src="/d.jpg" alt="2024">',
    '<input type="text" value="Prefilled user data">',
    '<input type="hidden" value="Hidden token text">',
    '<input type="text" alt="Not an image input">',
    '<div placeholder="Not a form field">x</div>',
    '<span data-title="Data attributes are not visible">y</span>',
    '<p class="notranslate"><img alt="Opted out alt" src="/e.jpg"></p>',
    '<div translate="no"><a title="Opted out title" href="/">z</a></div>',
    '<script title="Script title"></script>',
]));

foreach (['IMG_1234.jpg', 'https://example.com/b.jpg', '2024', 'Prefilled user data', 'Hidden token text',
    'Not an image input', 'Not a form field', 'Data attributes are not visible', 'Opted out alt',
    'Opted out title', 'Script title'] as $unexpected) {
    test_ok("'{$unexpected}' is not a unit", !in_array($unexpected, $skipped, true), 'got ' . json_encode($skipped));
}
test_ok('an empty alt is not a unit', !in_array('', $skipped, true), 'got ' . json_encode($skipped));

test_section('extractor: whitespace in an attribute is tidied like a block unit:');

test_ok('runs collapse, ends trim', in_array('A tidy title', $extractor->units("<a href=\"/\" title=\"  A\n   tidy\ttitle \">t</a>"), true));

// ── 2. Extractor render: attributes are replaced, block text too ────────────

test_section('extractor render: attribute translations are applied:');

$map = [
    'A dog on the beach'           => 'Un chien sur la plage',
    'Look at {1/} this dog'        => 'Regardez {1/} ce chien',
    'Send message'                 => 'Envoyer le message',
    'Tom & "Jerry"'                => 'Tom & "Jerry" onerror="alert(1)',
];
$translate = static fn(string $unit): ?string => $map[$unit] ?? null;

$out = $extractor->render('<p>Look at <img src="/dog.jpg" alt="A dog on the beach"> this dog</p>', $translate);
test_ok('an <img alt> inside a translated paragraph keeps the translated alt',
    strpos($out, 'alt="Un chien sur la plage"') !== false && strpos($out, 'Regardez') !== false, $out);

$out = $extractor->render('<form><input type="submit" value="Send message"></form>', $translate);
test_ok('a submit button value is translated', strpos($out, 'value="Envoyer le message"') !== false, $out);

$out = $extractor->render('<img src="/x.jpg" alt="Tom &amp; &quot;Jerry&quot;">', $translate);
// The real risk inside an attribute is a quote closing it early; `<` inside a
// quoted value is inert in HTML5, so the serializer rightly leaves it alone.
test_ok('entities decode for the lookup and a quote in the translation cannot close the attribute',
    strpos($out, '" onerror=') === false && strpos($out, 'Tom &amp; &quot;Jerry&quot;') !== false, $out);

$out = $extractor->render('<img src="/x.jpg" alt="Untranslated alt">', $translate);
test_ok('an attribute with no translation is left exactly as-is', strpos($out, 'alt="Untranslated alt"') !== false, $out);

// ── 3. Post content: discovered on save, rendered translated, pruned on edit ─

test_section('post content: image block alt end to end:');

global $wpdb;

$repo    = new WpDbSourceRepository($wpdb, new WpDbBoardQueries($wpdb));
$scanner = new StructuredSourceScanner($repo, $extractor);
$PP      = TranslationMemorySchema::parentTables()['post'];
$now     = current_time('mysql');

$imageBlock = static fn(string $alt): string => '<!-- wp:image {"id":1} --><figure class="wp-block-image">'
    . '<img src="/wp-content/uploads/dog.jpg" alt="' . esc_attr($alt) . '"/>'
    . '<figcaption>LINGOWP_TEST caption</figcaption></figure><!-- /wp:image -->';

$postId = test_insert($wpdb->posts, [
    'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => $now,
    'post_content' => $imageBlock('LINGOWP_TEST a dog on the beach'),
    'post_title' => 'LINGOWP_TEST attr fixture', 'post_excerpt' => '', 'post_status' => 'publish',
    'comment_status' => 'closed', 'ping_status' => 'closed',
    'post_name' => 'lingowp-test-attr-' . wp_generate_password(8, false),
    'post_modified' => $now, 'post_modified_gmt' => $now, 'post_type' => 'post',
]);
$scanner->refreshPost($postId);

$contentRows = static fn(int $id): array => $wpdb->get_col($wpdb->prepare(
    "SELECT original_text FROM {$PP} WHERE post_id = %d AND field = 'post_content' ORDER BY id", $id
));

$rows = $contentRows($postId);
test_ok('the image alt is discovered as its own post_content row', in_array('LINGOWP_TEST a dog on the beach', $rows, true), 'got ' . json_encode($rows));
test_ok('the caption is still discovered next to it', in_array('LINGOWP_TEST caption', $rows, true), 'got ' . json_encode($rows));

$addTranslation = static function (string $source, string $translated) use ($wpdb, $PP): void {
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$PP}_translation (original_hash, lang_code, translated_text, status, created_at, updated_at)
         VALUES (UNHEX(%s), 'fr', %s, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text)",
        bin2hex(TranslationKey::currentHash($source)), $translated
    ));
};
$addTranslation('LINGOWP_TEST a dog on the beach', 'LINGOWP_TEST un chien sur la plage');
$addTranslation('LINGOWP_TEST caption', 'LINGOWP_TEST légende');

$languageRegistry = new \LingoWP\Language\Infrastructure\OptionLanguageRegistry();
$resolver = new \LingoWP\Resolution\StructuredRenderResolver(
    new \LingoWP\Language\Application\ResolveRequestLanguage($languageRegistry),
    new \LingoWP\Resolution\RequestContext(),
    $repo,
    $extractor,
    new \LingoWP\Resolution\PageOwnedHashes(),
    $languageRegistry
);
$ref = new ReflectionClass($resolver);
foreach (['active' => true, 'lang' => 'fr'] as $prop => $value) {
    $ref->getProperty($prop)->setAccessible(true);
    $ref->getProperty($prop)->setValue($resolver, $value);
}

$rendered = (string) $resolver->content(get_post($postId)->post_content);
test_ok('the French render carries the translated alt', strpos($rendered, 'alt="LINGOWP_TEST un chien sur la plage"') !== false, $rendered);
test_ok('the French render carries the translated caption', strpos($rendered, 'LINGOWP_TEST légende') !== false, $rendered);

// A stray placeholder in an attribute translation (no guard rejects it — the
// source has no markers, same as any plain unit) stays inert literal text.
$addTranslation('LINGOWP_TEST a dog on the beach', 'LINGOWP_TEST {1}chien{/1}');
wp_cache_flush();
foreach (['hashMap', 'hashLoaded'] as $prop) { // drop the per-request cache so the new row is read
    $ref->getProperty($prop)->setAccessible(true);
    $ref->getProperty($prop)->setValue($resolver, []);
}
$rendered = (string) $resolver->content(get_post($postId)->post_content);
test_ok('a stray {1} in an attribute translation renders as literal text, never markup',
    strpos($rendered, 'alt="LINGOWP_TEST {1}chien{/1}"') !== false, $rendered);

$wpdb->update($wpdb->posts, ['post_content' => $imageBlock('LINGOWP_TEST a cat on the sofa')], ['ID' => $postId]);
clean_post_cache($postId);
$scanner->refreshPost($postId);
$rows = $contentRows($postId);
test_ok('editing the alt replaces the old row with the new text',
    in_array('LINGOWP_TEST a cat on the sofa', $rows, true) && !in_array('LINGOWP_TEST a dog on the beach', $rows, true),
    'got ' . json_encode($rows));

// ── 4. Template HTML: discovered with an @attr selector, translated on the page ─

test_section('template HTML: hardcoded attribute text:');

$HP       = TranslationMemorySchema::parentTables()['html'];
$renderer = new \LingoWP\Resolution\HtmlRenderer($extractor);
$discovery = new \LingoWP\Extraction\Html\HtmlDiscovery($renderer, new \LingoWP\Extraction\OwnedStringIndex($wpdb), $repo);

$page = '<!doctype html><html><head><title>t</title></head><body>'
    . '<header><form role="search"><input type="search" placeholder="LINGOWP_TEST search the site"></form>'
    . '<a href="/" aria-label="LINGOWP_TEST back to home"><svg aria-hidden="true"></svg></a></header>'
    . '<main><p>LINGOWP_TEST cat on the sofa <img src="/x.jpg" alt="LINGOWP_TEST a cat on the sofa"></p></main>'
    . '</body></html>';
$discovery->discover($page, '/lingowp-test-attr/');

$htmlRow = static fn(string $text): ?array => $wpdb->get_row($wpdb->prepare(
    "SELECT selector, source_url FROM {$HP} WHERE original_hash = UNHEX(%s)",
    bin2hex(TranslationKey::currentHash($text))
), ARRAY_A);

$row = $htmlRow('LINGOWP_TEST search the site');
test_ok('a hardcoded placeholder is minted as a html row', $row !== null);
test_ok('its board selector names the attribute', $row !== null && $row['selector'] === 'header>form>input@placeholder', 'got ' . json_encode($row));
$row = $htmlRow('LINGOWP_TEST back to home');
test_ok('a hardcoded aria-label is minted with an @aria-label selector', $row !== null && $row['selector'] === 'header>a@aria-label', 'got ' . json_encode($row));
test_ok('an alt already owned by post content is not duplicated as a html row', $htmlRow('LINGOWP_TEST a cat on the sofa') === null);

$long = str_repeat('<div>', 80) . '<img src="/y.jpg" alt="LINGOWP_TEST deeply nested alt">' . str_repeat('</div>', 80);
$discovery->discover('<!doctype html><html><body>' . $long . '</body></html>', '/lingowp-test-attr-deep/');
$row = $htmlRow('LINGOWP_TEST deeply nested alt');
test_ok('a capped selector keeps its @alt suffix and fits the column',
    $row !== null && substr($row['selector'], -4) === '@alt' && strlen($row['selector']) <= 255, 'got ' . json_encode($row));

$map = [
    bin2hex(TranslationKey::currentHash('LINGOWP_TEST search the site')) => 'LINGOWP_TEST rechercher sur le site',
    bin2hex(TranslationKey::currentHash('LINGOWP_TEST back to home'))    => 'LINGOWP_TEST retour à l\'accueil',
];
$out = $renderer->render($page, $map);
test_ok('the page pass translates the placeholder', strpos($out, 'placeholder="LINGOWP_TEST rechercher sur le site"') !== false, $out);
test_ok('the page pass translates the aria-label', strpos($out, 'aria-label="LINGOWP_TEST retour à l\'accueil"') !== false, $out);
test_ok('the page pass leaves an attribute without a translation alone', strpos($out, 'alt="LINGOWP_TEST a cat on the sofa"') !== false, $out);

test_done();

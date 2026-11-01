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
    '<input type="submit" name="action" value="Delete everything">',
    '<input type="submit" name=" " value="Whitespace name is still a name">',
    '<input type="text" alt="Not an image input">',
    '<div placeholder="Not a form field">x</div>',
    '<span data-title="Data attributes are not visible">y</span>',
    '<p class="notranslate"><img alt="Opted out alt" src="/e.jpg"></p>',
    '<div translate="no"><a title="Opted out title" href="/">z</a></div>',
    '<script title="Script title"></script>',
]));

foreach (['IMG_1234.jpg', 'https://example.com/b.jpg', '2024', 'Prefilled user data', 'Hidden token text',
    'Delete everything' /* named submit: its value is form data */, 'Whitespace name is still a name',
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

// Core's lightbox (render_block_core/image, 15) copies the alt into
// Interactivity state while do_blocks() runs — before any translation pass.
// The final-HTML pass (ImageLightboxState) gives the state the image's final
// alt. Full chain: do_blocks() → the_content pass → page pass.
test_section('image lightbox state follows the translated alt:');

$lightboxPage = static function (string $blocks, callable $contentPass, array $pageMap): array {
    $body = $contentPass(do_blocks($blocks));
    $data = apply_filters('script_module_data_@wordpress/interactivity', []);
    $html = '<!doctype html><html><head></head><body>' . $body
        . '<script type="application/json" id="wp-script-module-data-@wordpress/interactivity">'
        . wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP) . '</script></body></html>';
    $out = (new \LingoWP\Resolution\HtmlRenderer(new HtmlUnitExtractor(new TranslationUnitCodec())))->render(
        $html, $pageMap, null, static function (\DOMDocument $dom): void { (new \LingoWP\Resolution\ImageLightboxState())->sync($dom); }
    );
    preg_match('#id="wp-script-module-data-@wordpress/interactivity">(.*?)</script>#s', $out, $m);
    $state = json_decode($m[1] ?? '', true)['state']['core/image']['metadata'] ?? [];
    preg_match('#data-wp-key="([^"]+)"#', $out, $k);
    return [$out, $state[$k[1] ?? ''] ?? null];
};
$lightboxBlock = static fn(string $alt): string => '<!-- wp:image {"lightbox":{"enabled":true}} --><figure class="wp-block-image">'
    . '<img src="/wp-content/uploads/horse.jpg" alt="' . $alt . '"/></figure><!-- /wp:image -->';

$addTranslation('LINGOWP_TEST a lightbox horse', 'LINGOWP_TEST un cheval');
[$out, $entry] = $lightboxPage($lightboxBlock('LINGOWP_TEST a lightbox horse'), static fn(string $html): string => (string) $resolver->content($html), []);
test_ok('post content: the visible alt is translated', strpos($out, 'alt="LINGOWP_TEST un cheval"') !== false, $out);
test_ok('post content: the lightbox state alt is translated', ($entry['alt'] ?? null) === 'LINGOWP_TEST un cheval', 'got ' . json_encode($entry));
test_ok('post content: the dialog label carries the translated alt, not the source',
    is_string($entry['customAriaLabel'] ?? null) && strpos($entry['customAriaLabel'], 'LINGOWP_TEST un cheval') !== false
    && strpos($entry['customAriaLabel'], 'lightbox horse') === false, 'got ' . json_encode($entry));

// A short alt that is also a word of the label text: only the alt (the label's
// trailing %s) is swapped, never the label's own words.
$sync = new \LingoWP\Resolution\ImageLightboxState();
$doc  = (new \Masterminds\HTML5())->loadHTML('<!doctype html><html><body>'
    . '<figure class="wp-block-image wp-lightbox-container" data-wp-key="k1"><img src="/i.jpg" alt="photo"></figure>'
    . '<script type="application/json" id="wp-script-module-data-@wordpress/interactivity">'
    . wp_json_encode(['state' => ['core/image' => ['metadata' => ['k1' => ['alt' => 'image', 'customAriaLabel' => 'Enlarged image: image']]]]], JSON_HEX_TAG)
    . '</script></body></html>');
$sync->sync($doc);
$entry = json_decode($doc->getElementById('wp-script-module-data-@wordpress/interactivity')->textContent, true)['state']['core/image']['metadata']['k1'];
test_ok('only the trailing alt inside the label is swapped', $entry['customAriaLabel'] === 'Enlarged image: photo' && $entry['alt'] === 'photo', 'got ' . json_encode($entry));

// Codex review 3: the rest of the shared payload round-trips unchanged ({} stays {}),
// older core layouts (6.5–6.8) are covered, and labels are rebuilt from core's
// template wherever the locale puts %s — never by searching the label text.
$syncDoc = static function (string $body, array $data): \DOMDocument {
    $doc = (new \Masterminds\HTML5())->loadHTML('<!doctype html><html><body>' . $body
        . ($data === [] ? '' : '<script type="application/json" id="wp-script-module-data-@wordpress/interactivity">'
            . wp_json_encode($data, JSON_HEX_TAG) . '</script>') . '</body></html>');
    (new \LingoWP\Resolution\ImageLightboxState())->sync($doc);
    return $doc;
};
$rawData = static fn(\DOMDocument $doc): string => (string) $doc->getElementById('wp-script-module-data-@wordpress/interactivity')->textContent;
$ctx     = static fn(\DOMDocument $doc): array => (array) json_decode($doc->getElementsByTagName('figure')->item(0)->getAttribute('data-wp-context'), true);

$doc = $syncDoc('<figure class="wp-block-image wp-lightbox-container" data-wp-key="k9"><img src="/i.jpg" alt="photo"></figure>',
    ['state' => ['other/store' => ['quantities' => new \stdClass(), 'list' => ['0' => 'a', '1' => 'b']],
        'core/image' => ['metadata' => ['k9' => ['alt' => 'image', 'customAriaLabel' => 'Enlarged image: image']]]]]);
test_ok('another store\'s empty object stays an object ({} not [])', strpos($rawData($doc), '"quantities":{}') !== false, $rawData($doc));

// 6.8: state keyed by the context's imageId (no data-wp-key); ariaLabel is a constant.
$doc = $syncDoc('<figure class="wp-block-image wp-lightbox-container" data-wp-interactive="core/image" data-wp-context=\'{"imageId":"k8"}\'><img src="/i.jpg" alt="photo"></figure>',
    ['state' => ['core/image' => ['metadata' => ['k8' => ['alt' => 'image', 'ariaLabel' => 'Enlarged image']]]]]);
$entry = json_decode($rawData($doc), true)['state']['core/image']['metadata']['k8'];
test_ok('6.7–6.8 layout: state found through the context imageId', $entry['alt'] === 'photo' && $entry['ariaLabel'] === 'Enlarged image', 'got ' . json_encode($entry));

// 6.7: same, plus "Enlarge image: %s" in the state label and on the trigger button.
$doc = $syncDoc('<figure class="wp-block-image wp-lightbox-container" data-wp-context=\'{"imageId":"k7"}\'><img src="/i.jpg" alt="photo">'
    . '<button class="lightbox-trigger" type="button" aria-label="Enlarge image: image"></button></figure>',
    ['state' => ['core/image' => ['metadata' => ['k7' => ['alt' => 'image', 'ariaLabel' => 'Enlarge image: image']]]]]);
$entry  = json_decode($rawData($doc), true)['state']['core/image']['metadata']['k7'];
$button = $doc->getElementsByTagName('button')->item(0)->getAttribute('aria-label');
test_ok('6.7 layout: state label and trigger button label rebuilt around the new alt',
    $entry['ariaLabel'] === 'Enlarge image: photo' && $button === 'Enlarge image: photo', 'got ' . json_encode([$entry, $button]));

// 6.5–6.6: alt and label in the figure's own context, no shared state needed.
$doc = $syncDoc('<figure class="wp-block-image wp-lightbox-container" data-wp-context=\'{"uploadedSrc":"/i.jpg","ariaLabel":"Enlarge image: image","alt":"image"}\'>'
    . '<img src="/i.jpg" alt="photo"><button class="lightbox-trigger" type="button" aria-label="Enlarge image: image"></button></figure>', []);
$c = $ctx($doc);
test_ok('6.5–6.6 layout: the figure context alt and label follow the image',
    ($c['alt'] ?? '') === 'photo' && ($c['ariaLabel'] ?? '') === 'Enlarge image: photo' && ($c['uploadedSrc'] ?? '') === '/i.jpg', 'got ' . json_encode($c));

// A locale that puts %s first.
$frTemplate = static fn($t, $text, $domain) => ($domain === 'default' && $text === 'Enlarged image: %s') ? '%s (image agrandie)' : $t;
add_filter('gettext', $frTemplate, 10, 3);
$doc = $syncDoc('<figure class="wp-block-image wp-lightbox-container" data-wp-key="k4"><img src="/i.jpg" alt="photo"></figure>',
    ['state' => ['core/image' => ['metadata' => ['k4' => ['alt' => 'image', 'customAriaLabel' => 'image (image agrandie)']]]]]);
remove_filter('gettext', $frTemplate, 10);
$entry = json_decode($rawData($doc), true)['state']['core/image']['metadata']['k4'];
test_ok('%s-first locale: the label is rebuilt from the template, the label\'s own words untouched',
    $entry['customAriaLabel'] === 'photo (image agrandie)', 'got ' . json_encode($entry));

$doc = $syncDoc('<figure class="wp-block-image wp-lightbox-container" data-wp-key="k5"><img src="/i.jpg" alt="photo"></figure>',
    ['state' => ['core/image' => ['metadata' => ['k5' => ['alt' => 'image', 'customAriaLabel' => 'Some custom label: image']]]]]);
$entry = json_decode($rawData($doc), true)['state']['core/image']['metadata']['k5'];
test_ok('a label matching no known template is left alone (alt still updated)',
    $entry['customAriaLabel'] === 'Some custom label: image' && $entry['alt'] === 'photo', 'got ' . json_encode($entry));

// Template image translated only through the html table (no post row).
$templateMap = [bin2hex(TranslationKey::currentHash('LINGOWP_TEST a template zebra')) => 'LINGOWP_TEST un zèbre'];
[$out, $entry] = $lightboxPage($lightboxBlock('LINGOWP_TEST a template zebra'), static fn(string $html): string => $html, $templateMap);
test_ok('template html: the lightbox state alt follows the page-pass translation', ($entry['alt'] ?? null) === 'LINGOWP_TEST un zèbre', 'got ' . json_encode($entry));

// Opted out: neither the image nor its state are touched, even with a translation available.
[$out, $entry] = $lightboxPage('<!-- wp:group {"className":"notranslate"} --><div class="wp-block-group notranslate">'
    . $lightboxBlock('LINGOWP_TEST a template zebra') . '</div><!-- /wp:group -->', static fn(string $html): string => $html, $templateMap);
test_ok('opted out: the visible alt stays the source', strpos($out, 'alt="LINGOWP_TEST a template zebra"') !== false, $out);
test_ok('opted out: the lightbox state stays the source', ($entry['alt'] ?? null) === 'LINGOWP_TEST a template zebra', 'got ' . json_encode($entry));

$wpdb->update($wpdb->posts, ['post_content' => $imageBlock('LINGOWP_TEST a cat on the sofa')], ['ID' => $postId]);
clean_post_cache($postId);
$scanner->refreshPost($postId);
$rows = $contentRows($postId);
test_ok('editing the alt replaces the old row with the new text',
    in_array('LINGOWP_TEST a cat on the sofa', $rows, true) && !in_array('LINGOWP_TEST a dog on the beach', $rows, true),
    'got ' . json_encode($rows));

// ── 3b. Upgrade: attribute units must not inherit a deleted paragraph's translation ─
// Codex review: an existing post's first save after the upgrade discovers its
// attribute units as NEW; if that save also deleted one paragraph, reconcile's
// one-removed/one-inserted rule copied the paragraph's translation onto the alt.

test_section('upgrade: a deleted paragraph\'s translation never lands on an alt:');

$upgradePost = static function (string $suffix) use ($wpdb, $now, $scanner, $PP, $addTranslation): array {
    $alt     = "LINGOWP_TEST upgrade alt {$suffix}";
    $welcome = "LINGOWP_TEST upgrade welcome {$suffix}";
    $body    = static fn(bool $withWelcome): string => ($withWelcome ? '<!-- wp:paragraph --><p>' . $welcome . '</p><!-- /wp:paragraph -->' : '')
        . '<!-- wp:image --><figure class="wp-block-image"><img src="/u.jpg" alt="' . $alt . '"/></figure><!-- /wp:image -->';
    $id = test_insert($wpdb->posts, [
        'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => $now, 'post_content' => $body(true),
        'post_title' => "LINGOWP_TEST upgrade {$suffix}", 'post_excerpt' => '', 'post_status' => 'publish',
        'comment_status' => 'closed', 'ping_status' => 'closed', 'post_name' => 'lingowp-test-upgrade-' . strtolower($suffix),
        'post_modified' => $now, 'post_modified_gmt' => $now, 'post_type' => 'post',
    ]);
    $scanner->refreshPost($id);
    // Pre-upgrade state: discovered before attribute units existed.
    $wpdb->query($wpdb->prepare("DELETE FROM {$PP} WHERE post_id = %d AND original_hash = UNHEX(%s)", $id, bin2hex(TranslationKey::currentHash($alt))));
    $addTranslation($welcome, "LINGOWP_TEST bienvenue {$suffix}");
    return [$id, $alt, $body];
};
$altTranslation = static fn(string $alt): ?string => $wpdb->get_var($wpdb->prepare(
    "SELECT translated_text FROM {$PP}_translation WHERE original_hash = UNHEX(%s) AND lang_code = 'fr'", bin2hex(TranslationKey::currentHash($alt))
));
$editRemovingWelcome = static function (int $id, callable $body) use ($wpdb, $scanner): void {
    $wpdb->update($wpdb->posts, ['post_content' => $body(false)], ['ID' => $id]);
    clean_post_cache($id);
    $scanner->refreshPost($id);
};

// Without the one-time rescan: reproduces the graft (documents why the rescan exists).
[$idA, $altA, $bodyA] = $upgradePost('A');
$editRemovingWelcome($idA, $bodyA);
test_ok('reproduced: edit-first grafts the paragraph translation onto the alt', $altTranslation($altA) === 'LINGOWP_TEST bienvenue A', 'got ' . var_export($altTranslation($altA), true));

// With it: the rescan discovers the alt on its own (nothing removed), then the edit.
[$idB, $altB, $bodyB] = $upgradePost('B');
$scanner->refreshPost($idB); // what the one-time InitialDiscoveryScan pass does per post
$editRemovingWelcome($idB, $bodyB);
test_ok('rescan-first: the alt keeps no translation', $altTranslation($altB) === null, 'got ' . var_export($altTranslation($altB), true));

$scanClass = \LingoWP\Extraction\Structured\InitialDiscoveryScan::class;
$scan      = new $scanClass($wpdb, $scanner, new \LingoWP\Extraction\OptionSourceScanner($repo, $wpdb));
$pending   = static fn(): int => count(as_get_scheduled_actions(['hook' => $scanClass::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1], 'ids'));
$resetRescan = static function () use ($scanClass): void {
    update_option($scanClass::VERSION_OPTION, 1);
    delete_option($scanClass::OPTION_FLAG);
    delete_transient($scanClass::RETRY_TRANSIENT);
    as_unschedule_all_actions($scanClass::HOOK);
};

$resetRescan();
$scan->maybeEnqueue();
test_ok('a site on an older discovery version gets the one-time rescan queued', $pending() === 1, 'pending=' . $pending());
test_ok('the version is NOT stamped yet — only a completed pass stamps it', (int) get_option($scanClass::VERSION_OPTION) === 1);
$scan->maybeEnqueue();
test_ok('while it runs, later requests queue no second chain', $pending() === 1, 'pending=' . $pending());

as_unschedule_all_actions($scanClass::HOOK); // the chain died mid-way
$scan->maybeEnqueue();
test_ok('a dead chain is not re-queued on every request (daily throttle)', $pending() === 0, 'pending=' . $pending());
delete_transient($scanClass::RETRY_TRANSIENT); // a day later
$scan->maybeEnqueue();
test_ok('…and is re-queued once the throttle lapses', $pending() === 1, 'pending=' . $pending());

as_unschedule_all_actions($scanClass::HOOK);
// Codex review 3: a chain queued by older discovery rules (no 'v' on its cursor)
// must not complete the upgrade — it is restarted as a full current pass.
$scan->runBatch(['phase' => 'terms', 'after' => PHP_INT_MAX]); // old chain's last batch
test_ok('an older chain finishing does NOT stamp the version', (int) get_option($scanClass::VERSION_OPTION) === 1);
$restarted = as_get_scheduled_actions(['hook' => $scanClass::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1]);
$restartArgs = $restarted !== [] ? reset($restarted)->get_args()['cursor'] ?? [] : [];
test_ok('…it is restarted from the first post under the current rules',
    ($restartArgs['phase'] ?? '') === 'posts' && ($restartArgs['after'] ?? -1) === 0 && ($restartArgs['v'] ?? 0) === $scanClass::DISCOVERY_VERSION,
    'got ' . json_encode($restartArgs));
as_unschedule_all_actions($scanClass::HOOK);

$scan->runBatch(['phase' => 'terms', 'after' => PHP_INT_MAX, 'v' => $scanClass::DISCOVERY_VERSION]); // current chain's last batch
test_ok('a completed current pass stamps the version', (int) get_option($scanClass::VERSION_OPTION) === $scanClass::DISCOVERY_VERSION);
delete_transient($scanClass::RETRY_TRANSIENT);
$scan->maybeEnqueue();
test_ok('after completion nothing is queued again', $pending() === 0, 'pending=' . $pending());

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

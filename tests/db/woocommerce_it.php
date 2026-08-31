<?php

/**
 * lingowp-woocommerce add-on coverage — mirrors the manual wp-cli verification
 * run against a real product/variation/attribute/settings during development.
 * Run: wp eval-file tests/db/woocommerce_it.php
 *
 * TEMPORARY HOME, by request: this exercises the separate lingowp-woocommerce
 * add-on plugin's classes (LingoWPWoo\*), not core — kept here for now so it
 * runs via one `composer test:db` during active development. Move back to
 * lingowp-woocommerce/tests/ once the add-on stabilizes (see
 * lingowp_woocommerce_audit_and_addon memory).
 */

require __DIR__ . '/_harness.php';

use LingoWP\Database\TranslationMemorySchema;
use LingoWPWoo\AttributeLabelResolver;
use LingoWPWoo\AttributeLabelSync;
use LingoWPWoo\LocalAttributeResolver;
use LingoWPWoo\LocalAttributeSync;
use LingoWPWoo\LocalAttributeVariationOptionResolver;
use LingoWPWoo\NonPublicTaxonomyFilter;
use LingoWPWoo\ProductMetaKeyFilter;
use LingoWPWoo\RequestActivation;
use LingoWPWoo\SettingsApiOptionFilter;
use LingoWPWoo\SettingsOptionFilter;
use LingoWPWoo\TaxRateLabelResolver;
use LingoWPWoo\TaxRateSync;
use LingoWPWoo\VariationDescriptionScanner;
use LingoWPWoo\VariationDescriptionResolver;

global $wpdb;

test_guard();

if (!class_exists('WooCommerce')) {
    fwrite(STDERR, "SKIPPED: WooCommerce is not active; this suite needs it.\n");
    exit(0);
}
if (!class_exists('LingoWPWoo\\NonPublicTaxonomyFilter')) {
    fwrite(STDERR, "SKIPPED: lingowp-woocommerce add-on is not active.\n");
    exit(0);
}

test_begin();

$parents = TranslationMemorySchema::parentTables();
$MP      = $parents['meta'];
$now     = current_time('mysql');

// ── 1. non-public WooCommerce taxonomies get added to the taxonomy scan list ─

test_section('non-public WooCommerce taxonomies:');

$attrId = wc_create_attribute(['name' => 'LingoWP Test Color ' . wp_generate_password(6, false)]);
test_ok('wc_create_attribute() succeeded', !is_wp_error($attrId), is_wp_error($attrId) ? $attrId->get_error_message() : '');

if (!is_wp_error($attrId)) {
    $taxonomy = wc_attribute_taxonomy_name_by_id($attrId);
    if (!taxonomy_exists($taxonomy)) {
        register_taxonomy($taxonomy, 'product', ['public' => false, 'hierarchical' => false]);
    }

    $withFilter = NonPublicTaxonomyFilter::addTaxonomies(['category']);
    test_ok('filter keeps the incoming list', in_array('category', $withFilter, true));
    test_ok('filter adds the new pa_ taxonomy', in_array($taxonomy, $withFilter, true));
}

test_ok('filter adds product_shipping_class', in_array('product_shipping_class', NonPublicTaxonomyFilter::addTaxonomies([]), true));

// A taxonomy filter with wc_get_attribute_taxonomy_names() unavailable must
// no-op rather than error (defensive; can't happen with WooCommerce active,
// but the guard exists — assert it directly).
test_ok(
    'filter is a pure array transform (idempotent on an already-included name)',
    in_array('category', NonPublicTaxonomyFilter::addTaxonomies(NonPublicTaxonomyFilter::addTaxonomies(['category'])), true)
);

// ── 2. settings option allow-list ────────────────────────────────────────────

test_section('WooCommerce settings option allow-list:');

$paths = SettingsOptionFilter::addOptions(['blogname' => ['']]);
test_ok('existing allow-list entries are kept', isset($paths['blogname']));
foreach ([
    'woocommerce_demo_store_notice',
    'woocommerce_email_footer_text',
    'woocommerce_email_from_name',
    'woocommerce_checkout_terms_and_conditions_checkbox_text',
    'woocommerce_checkout_privacy_policy_text',
] as $opt) {
    test_ok("allow-lists $opt", isset($paths[$opt]) && $paths[$opt] === ['']);
}
test_ok('does NOT allow-list the store address (factual, not prose)', !isset($paths['woocommerce_store_address']));

// ── 2b. product post-meta allow-list ─────────────────────────────────────────

test_section('product post-meta allow-list:');

$metaKeys = ProductMetaKeyFilter::addKeys(['_yoast_wpseo_title']);
test_ok('keeps an existing allow-listed key', in_array('_yoast_wpseo_title', $metaKeys, true));
foreach (['_purchase_note', '_button_text', '_downloadable_files'] as $key) {
    test_ok("allow-lists $key", in_array($key, $metaKeys, true));
}

// end-to-end: a real product's purchase note and downloadable file name are
// discovered by core's own (unmodified) post-meta scanner once the key is
// allow-listed — no add-on-side discovery code needed, unlike the variation
// case, since `product` is already a scannable post type.
add_filter('lingowp_translatable_meta_keys', [ProductMetaKeyFilter::class, 'addKeys']);

$productId = test_insert($wpdb->posts, [
    'post_author'       => 1,
    'post_date'         => $now,
    'post_date_gmt'     => $now,
    'post_content'      => '',
    'post_title'        => 'LINGOWP_TEST product meta fixture',
    'post_excerpt'      => '',
    'post_status'       => 'publish',
    'comment_status'    => 'closed',
    'ping_status'       => 'closed',
    'post_name'         => 'lingowp-test-product-meta-' . wp_generate_password(8, false),
    'post_modified'     => $now,
    'post_modified_gmt' => $now,
    'post_type'         => 'product',
]);

$noteSentinel = 'LINGOWP_TEST ZQWOOIT purchase note sentinel.';
update_post_meta($productId, '_purchase_note', $noteSentinel);
$downloadName = 'LINGOWP_TEST ZQWOOIT download name sentinel';
update_post_meta($productId, '_downloadable_files', [
    'abc123' => ['name' => $downloadName, 'file' => 'https://example.com/file.pdf'],
]);

$coreScanner = new \LingoWP\Extraction\Structured\StructuredSourceScanner(
    \LingoWP\Database\Repository\WpDbSourceRepository::instance(),
    new \LingoWP\Shared\Parsing\HtmlUnitExtractor(new \LingoWP\Shared\Parsing\TranslationUnitCodec())
);
$coreScanner->refreshPostMetaKey($productId, '_purchase_note');
$coreScanner->refreshPostMetaKey($productId, '_downloadable_files');

$noteFound = test_var(
    "SELECT id FROM {$MP} WHERE post_id = %d AND meta_key_hash = UNHEX(%s) AND original_text = %s",
    $productId, bin2hex(hash('sha256', '_purchase_note', true)), $noteSentinel
);
test_ok('purchase note discovered end-to-end via core\'s own post-meta scanner', $noteFound !== null);

$downloadNameFound = test_var(
    "SELECT id FROM {$MP} WHERE post_id = %d AND meta_key_hash = UNHEX(%s) AND original_text = %s",
    $productId, bin2hex(hash('sha256', '_downloadable_files', true)), $downloadName
);
test_ok('downloadable file name discovered', $downloadNameFound !== null);

$downloadUrlFound = test_var(
    "SELECT id FROM {$MP} WHERE post_id = %d AND meta_key_hash = UNHEX(%s) AND original_text = %s",
    $productId, bin2hex(hash('sha256', '_downloadable_files', true)), 'https://example.com/file.pdf'
);
test_ok('downloadable file URL leaf is NOT discovered (TranslatableText rejects bare URLs)', $downloadUrlFound === null);

remove_filter('lingowp_translatable_meta_keys', [ProductMetaKeyFilter::class, 'addKeys']);

// ── 2c. gateway/shipping-method/email settings, detected by field type ──────

test_section('settings-API prose detection (gateways, shipping methods, emails):');

$apiPaths = SettingsApiOptionFilter::addOptions([]);

test_ok(
    'BACS gateway: title/description/instructions included',
    isset($apiPaths['woocommerce_bacs_settings'])
        && array_diff(['title', 'description', 'instructions'], $apiPaths['woocommerce_bacs_settings']) === []
);
test_ok(
    'BACS gateway: account_details and enabled excluded (not text/safe_text/textarea)',
    !in_array('account_details', $apiPaths['woocommerce_bacs_settings'], true)
        && !in_array('enabled', $apiPaths['woocommerce_bacs_settings'], true)
);

test_ok(
    'order email: subject/heading/additional_content included',
    isset($apiPaths['woocommerce_new_order_settings'])
        && array_diff(['subject', 'heading', 'additional_content'], $apiPaths['woocommerce_new_order_settings']) === []
);
test_ok(
    'order email: recipient/cc/bcc excluded despite being type "text" (admin email addresses, not prose)',
    array_intersect(['recipient', 'cc', 'bcc'], $apiPaths['woocommerce_new_order_settings']) === []
);

// a real shipping method instance on zone 0 ("Rest of the World", excluded
// from WC_Shipping_Zones::get_zones() — must be reached via get_zone(0))
$zone       = new WC_Shipping_Zone(0);
$instanceId = $zone->add_shipping_method('flat_rate');
$methodKey  = 'woocommerce_flat_rate_' . $instanceId . '_settings';

$apiPathsWithMethod = SettingsApiOptionFilter::addOptions([]);
test_ok(
    'shipping method instance on zone 0 is included with its title field',
    isset($apiPathsWithMethod[$methodKey]) && in_array('title', $apiPathsWithMethod[$methodKey], true)
);

// end-to-end: a real gateway setting flows through core's own unmodified
// option scanner once this filter is wired in.
add_filter('lingowp_option_paths', [SettingsApiOptionFilter::class, 'addOptions']);

$gatewaySentinel = 'LINGOWP_TEST ZQWOOIT gateway title sentinel';
update_option('woocommerce_bacs_settings', [
    'title'           => $gatewaySentinel,
    'description'     => '',
    'instructions'    => '',
    'enabled'         => 'yes',
    'account_details' => [['bank_name' => 'LINGOWP_TEST Not A Real Bank']],
]);

(new \LingoWP\Extraction\OptionSourceScanner(\LingoWP\Database\Repository\WpDbSourceRepository::instance(), $wpdb))->scan('woocommerce_bacs_settings');

$OP = TranslationMemorySchema::parentTables()['option'];
$bacsOptionId = test_var("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", 'woocommerce_bacs_settings');
$gatewayFound = test_var(
    "SELECT id FROM {$OP} WHERE option_id = %d AND original_text = %s",
    $bacsOptionId, $gatewaySentinel
);
test_ok('gateway title discovered end-to-end via core\'s own option scanner', $gatewayFound !== null);

$bankNameLeaked = test_var(
    "SELECT id FROM {$OP} WHERE option_id = %d AND original_text = %s",
    $bacsOptionId, 'LINGOWP_TEST Not A Real Bank'
);
test_ok('account_details bank name is NOT discovered', $bankNameLeaked === null);

remove_filter('lingowp_option_paths', [SettingsApiOptionFilter::class, 'addOptions']);
$zone->delete_shipping_method($instanceId);
$zone->save();

// ── 3. variation description discovery (post meta, not post_content) ────────

test_section('variation description discovery:');

$variationId = test_insert($wpdb->posts, [
    'post_author'       => 1,
    'post_date'         => $now,
    'post_date_gmt'     => $now,
    'post_content'      => '', // WooCommerce always saves this empty for a variation
    'post_title'        => 'LINGOWP_TEST variation fixture',
    'post_excerpt'      => '',
    'post_status'       => 'publish',
    'comment_status'    => 'closed',
    'ping_status'       => 'closed',
    'post_name'         => 'lingowp-test-variation-' . wp_generate_password(8, false),
    'post_modified'     => $now,
    'post_modified_gmt' => $now,
    'post_type'         => 'product_variation',
]);

$sentinel = 'LINGOWP_TEST ZQWOOIT variation description sentinel.';
update_post_meta($variationId, VariationDescriptionScanner::META_KEY, $sentinel);
VariationDescriptionScanner::refresh($variationId);

$metaRow = test_var(
    "SELECT id FROM {$MP} WHERE post_id = %d AND meta_key_hash = UNHEX(%s) AND original_text = %s",
    $variationId, bin2hex(hash('sha256', VariationDescriptionScanner::META_KEY, true)), $sentinel
);
test_ok('description discovered as post meta, not post_content', $metaRow !== null);

// product_variation is not on core's scannable-type list — the generic
// scanner must never pick this up on its own (proves the add-on, not an
// accidental core change, is doing the work).
$coreScanner = new \LingoWP\Extraction\Structured\StructuredSourceScanner(
    \LingoWP\Database\Repository\WpDbSourceRepository::instance(),
    new \LingoWP\Shared\Parsing\HtmlUnitExtractor(new \LingoWP\Shared\Parsing\TranslationUnitCodec())
);
$beforeCount = test_count("SELECT COUNT(*) FROM {$MP} WHERE post_id = %d", $variationId);
$coreScanner->refreshPost($variationId); // must be a no-op: core doesn't scan this post type
$afterCount = test_count("SELECT COUNT(*) FROM {$MP} WHERE post_id = %d", $variationId);
test_ok('core scanner alone never discovers a variation (confirms the gap this add-on closes)', $beforeCount === $afterCount);

// removing the description prunes the source, same as any other meta key
delete_post_meta($variationId, VariationDescriptionScanner::META_KEY);
VariationDescriptionScanner::refresh($variationId);
$afterDelete = test_count("SELECT COUNT(*) FROM {$MP} WHERE post_id = %d", $variationId);
test_ok('clearing the description prunes its source row', $afterDelete === 0);

// re-add for the render test below
update_post_meta($variationId, VariationDescriptionScanner::META_KEY, $sentinel);
VariationDescriptionScanner::refresh($variationId);
$metaId = (int) test_var(
    "SELECT id FROM {$MP} WHERE post_id = %d AND meta_key_hash = UNHEX(%s)",
    $variationId, bin2hex(hash('sha256', VariationDescriptionScanner::META_KEY, true))
);

// ── 4. render swaps in the translation, re-formatted like WooCommerce would ──

test_section('variation description render:');

$lang = 'fr_FR';
$translated = 'LINGOWP_TEST ZQWOOIT phrase traduite.';
test_insert($MP . '_translation', [
    'parent_id'       => $metaId,
    'lang_code'       => $lang,
    'translated_text' => $translated,
    'status'          => 0,
    'created_at'      => $now,
    'updated_at'      => $now,
]);

$variation = new WC_Product_Variation($variationId);

// Forces RequestActivation::isActive()/lang() for every resolver in this add-on
// (VariationDescriptionResolver, AttributeLabelResolver, TaxRateLabelResolver
// all share it) — same reflection technique used elsewhere this session for
// core's own StructuredRenderResolver.
$activationRef = new ReflectionClass(RequestActivation::class);
$ctxProp = $activationRef->getProperty('context');
$ctxProp->setAccessible(true);
$ctxProp->setValue(null, new class extends \LingoWP\Resolution\RequestContext {
    public function shouldTranslate(): bool
    {
        return true;
    }
});
$langProp = $activationRef->getProperty('language');
$langProp->setAccessible(true);
$langProp->setValue(null, new class ($lang) extends \LingoWP\Language\Application\ResolveRequestLanguage {
    private string $l;
    public function __construct(string $l)
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

$data = VariationDescriptionResolver::translate(['variation_description' => wc_format_content($sentinel)], null, $variation);
test_ok('render swaps in the translated description', strpos($data['variation_description'], $translated) !== false);
test_ok('render output goes through wc_format_content, matching an untranslated description', $data['variation_description'] === wc_format_content($translated));

// ── 5. WooCommerce attribute label — option-backed, via the existing option pipeline ──

test_section('WooCommerce attribute label (option-backed, existing option:site pipeline):');

$attrId2 = wc_create_attribute(['name' => 'ZQWOOIT Attr ' . wp_generate_password(4, false), 'slug' => 'zqwooit' . wp_generate_password(4, false)]);
test_ok('wc_create_attribute() succeeded', !is_wp_error($attrId2), is_wp_error($attrId2) ? $attrId2->get_error_message() : '');

$labels = get_option(AttributeLabelSync::OPTION_NAME);
test_ok(
    'attribute label option updated automatically via the real woocommerce_attribute_added hook (no manual sync() call)',
    is_array($labels) && ($labels[$attrId2] ?? null) !== null
);

$attrOptionId = test_var("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", AttributeLabelSync::OPTION_NAME);
$labelRow = test_var("SELECT id FROM {$OP} WHERE option_id = %d AND original_text = %s", $attrOptionId, $labels[$attrId2]);
test_ok('discovered as an ordinary option row, same table every other option-sourced string uses', $labelRow !== null);

wc_update_attribute($attrId2, ['name' => 'ZQWOOIT Attr Updated', 'slug' => wc_attribute_taxonomy_slug(wc_attribute_taxonomy_name_by_id($attrId2))]);
$updatedLabels = get_option(AttributeLabelSync::OPTION_NAME);
test_ok('label update discovered via the real woocommerce_attribute_updated hook', ($updatedLabels[$attrId2] ?? null) === 'ZQWOOIT Attr Updated');

$attrTranslated = 'LINGOWP_TEST ZQWOOIT Attr Traduit';
test_insert($OP . '_translation', [
    'original_hash'   => hash('sha256', \LingoWP\Shared\Text\StringNormalizer::normalize('ZQWOOIT Attr Updated'), true),
    'lang_code'       => $lang,
    'translated_text' => $attrTranslated,
    'status'          => 2,
    'created_at'      => $now,
    'updated_at'      => $now,
]);

$attrTaxonomy = wc_attribute_taxonomy_name_by_id($attrId2);
$attrRenderResult = AttributeLabelResolver::translate('ZQWOOIT Attr Updated', $attrTaxonomy);
test_ok('render swaps in the translated attribute label (fetchHashTranslations against the "option" table)', $attrRenderResult === $attrTranslated);

$localAttrResult = AttributeLabelResolver::translate('Some Local Attribute Label', 'not_a_real_taxonomy_name');
test_ok('a LOCAL (non-taxonomy) product attribute label is left untouched', $localAttrResult === 'Some Local Attribute Label');

// Through WpDbSourceRepository, the same object CollectionBoard actually holds.
$repo = \LingoWP\Database\Repository\WpDbSourceRepository::instance();
$attrSummary = $repo->optionCollectionSummary($lang);
test_ok('board summary (option:site, the SAME card blogname/settings already use) sees the translated attribute label', $attrSummary['total'] >= 1 && $attrSummary['translated'] >= 1);

$reg = new \LingoWP\Language\Infrastructure\OptionLanguageRegistry();
$collectionBoard = new \LingoWP\Board\CollectionBoard($repo, $reg);
$collections = $collectionBoard->buildCollections($lang, '');
$optionCollection = null;
foreach ($collections as $c) {
    if ($c['id'] === 'option:site') {
        $optionCollection = $c;
    }
}
test_ok('CollectionBoard::buildCollections() includes it under the existing "Site settings" card — no dedicated card needed', $optionCollection !== null && $optionCollection['total'] >= 1);

wc_delete_attribute($attrId2);
$afterAttrDelete = get_option(AttributeLabelSync::OPTION_NAME);
test_ok('deleting the attribute prunes it from the option value', !isset($afterAttrDelete[$attrId2]));
$afterAttrRowDelete = test_var("SELECT id FROM {$OP} WHERE option_id = %d AND original_text = %s", $attrOptionId, 'ZQWOOIT Attr Updated');
test_ok('...and prunes the source row too (reconcileOption()\'s delete-stale rule, not a DB-level FK)', $afterAttrRowDelete === null);

// ── 6. WooCommerce tax rate name — same shape, same option pipeline ─────────

test_section('WooCommerce tax rate name (option-backed, existing option:site pipeline):');

$rateId2 = WC_Tax::_insert_tax_rate([
    'tax_rate_country' => '',
    'tax_rate_state'   => '',
    'tax_rate'         => '20.0000',
    'tax_rate_name'    => 'ZQWOOIT VAT',
    'tax_rate_class'   => '',
]);

$names = get_option(TaxRateSync::OPTION_NAME);
test_ok('tax rate name option updated automatically via the real woocommerce_tax_rate_added hook', is_array($names) && ($names[$rateId2] ?? null) === 'ZQWOOIT VAT');

$rateOptionId = test_var("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", TaxRateSync::OPTION_NAME);
$rateRow = test_var("SELECT id FROM {$OP} WHERE option_id = %d AND original_text = %s", $rateOptionId, 'ZQWOOIT VAT');
test_ok('discovered as an ordinary option row', $rateRow !== null);

WC_Tax::_update_tax_rate($rateId2, ['tax_rate_name' => 'ZQWOOIT VAT Updated', 'tax_rate' => '20.0000', 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate_class' => '']);
$updatedNames = get_option(TaxRateSync::OPTION_NAME);
test_ok('name update discovered via the real woocommerce_tax_rate_updated hook', ($updatedNames[$rateId2] ?? null) === 'ZQWOOIT VAT Updated');

$rateTranslated = 'LINGOWP_TEST ZQWOOIT TVA Traduit';
test_insert($OP . '_translation', [
    'original_hash'   => hash('sha256', \LingoWP\Shared\Text\StringNormalizer::normalize('ZQWOOIT VAT Updated'), true),
    'lang_code'       => $lang,
    'translated_text' => $rateTranslated,
    'status'          => 2,
    'created_at'      => $now,
    'updated_at'      => $now,
]);

$rateRenderResult = TaxRateLabelResolver::translate('ZQWOOIT VAT Updated', (string) $rateId2);
test_ok('render swaps in the translated tax rate name (fetchHashTranslations against the "option" table)', $rateRenderResult === $rateTranslated);

$zeroRatedResult = TaxRateLabelResolver::translate('ZQWOOIT VAT Updated', 'zero-rated');
test_ok('a non-numeric sentinel key (e.g. "zero-rated") is left untouched', $zeroRatedResult === 'ZQWOOIT VAT Updated');

$rateSummary = $repo->optionCollectionSummary($lang);
test_ok('board summary (option:site) sees the translated tax rate name too', $rateSummary['total'] >= 2 && $rateSummary['translated'] >= 2);

WC_Tax::_delete_tax_rate($rateId2);
$afterRateDelete = get_option(TaxRateSync::OPTION_NAME);
test_ok('deleting the tax rate prunes it from the option value', !isset($afterRateDelete[$rateId2]));
$afterRateRowDelete = test_var("SELECT id FROM {$OP} WHERE option_id = %d AND original_text = %s", $rateOptionId, 'ZQWOOIT VAT Updated');
test_ok('...and prunes the source row too', $afterRateRowDelete === null);

// ── 7. local (non-taxonomy) product attribute — name + split values ─────────

test_section('local product attribute (name + pipe-split values):');

$localProduct = new WC_Product_Simple();
$localProduct->set_name('LINGOWP_TEST local attribute product');
$localProduct->save();
$localProductId = $localProduct->get_id();

update_post_meta($localProductId, LocalAttributeSync::META_KEY, [
    'size' => [
        'name'         => 'Size',
        'value'        => 'ZQWOOIT Small | ZQWOOIT Medium | ZQWOOIT Large',
        'position'     => 0,
        'is_visible'   => 1,
        'is_variation' => 0,
        'is_taxonomy'  => 0,
    ],
    // a taxonomy entry alongside it — must never be touched by this feature
    'pa_' . wp_generate_password(6, false, false) => [
        'name'        => 'pa_zqwooitfake',
        'value'       => '',
        'position'    => 1,
        'is_visible'  => 1,
        'is_taxonomy' => 1,
    ],
]);
LocalAttributeSync::refresh($localProductId);

$metaKeyHash = bin2hex(hash('sha256', LocalAttributeSync::META_KEY, true));
$localRows = $wpdb->get_results($wpdb->prepare(
    "SELECT context, original_text FROM {$MP} WHERE post_id = %d AND meta_key_hash = UNHEX(%s)",
    $localProductId, $metaKeyHash
), ARRAY_A);
$localByContext = array_column($localRows, 'original_text', 'context');

test_ok('local attribute name discovered as its own leaf', ($localByContext['size.name'] ?? null) === 'Size');
test_ok('each pipe-separated value discovered as a SEPARATE leaf (not one joined string)', count($localRows) === 4);
test_ok('value 0 discovered', ($localByContext['size.value.0'] ?? null) === 'ZQWOOIT Small');
test_ok('value 1 discovered', ($localByContext['size.value.1'] ?? null) === 'ZQWOOIT Medium');
test_ok('value 2 discovered', ($localByContext['size.value.2'] ?? null) === 'ZQWOOIT Large');
test_ok('the taxonomy entry alongside it contributes nothing (already handled elsewhere)', !isset($localByContext['pa-zqwooitfake.name']) && count($localRows) === 4);

// end-to-end render: translate name + values, confirm the attribute object's
// own internal identity (get_name(), and the array key WC_Product::set_attributes()
// derives from it) is left UNCHANGED — a first version mutated set_name() here
// and broke that key, silently detaching a variation from its parent attribute.
foreach ($localByContext as $context => $text) {
    $rowId = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$MP} WHERE post_id = %d AND meta_key_hash = UNHEX(%s) AND context = %s",
        $localProductId, $metaKeyHash, $context
    ));
    $translated = 'LINGOWP_TEST traduit ' . $context;
    test_insert($MP . '_translation', [
        'parent_id' => $rowId, 'lang_code' => $lang, 'translated_text' => $translated,
        'status' => 2, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

$freshLocalProduct = wc_get_product($localProductId);
$attrs = $freshLocalProduct->get_attributes();
test_ok('attribute array key stays "size" (untranslated identity, not shifted by name translation)', isset($attrs['size']));
$sizeAttr = $attrs['size'] ?? null;
test_ok('get_name() stays the ORIGINAL label (never mutated — see class doc)', $sizeAttr !== null && $sizeAttr->get_name() === 'Size');
test_ok(
    'get_options() values are translated',
    $sizeAttr !== null && $sizeAttr->get_options() === [
        'LINGOWP_TEST traduit size.value.0',
        'LINGOWP_TEST traduit size.value.1',
        'LINGOWP_TEST traduit size.value.2',
    ]
);

// the Additional Information tab: both the label (via AttributeLabelResolver's
// second filter) and the values (via LocalAttributeResolver) must show translated
ob_start();
wc_display_product_attributes($freshLocalProduct);
$tabHtml = (string) ob_get_clean();
test_ok('tab shows the translated label', strpos($tabHtml, 'LINGOWP_TEST traduit size.name') !== false);
test_ok('tab shows the translated values', strpos($tabHtml, 'LINGOWP_TEST traduit size.value.0') !== false);

// the cart/order attribute summary line: wc_attribute_label($name, $product)
$cartLineLabel = wc_attribute_label('size', $freshLocalProduct);
test_ok('cart/order attribute summary label is translated too (a second call site than the tab)', $cartLineLabel === 'LINGOWP_TEST traduit size.name');

// ── 8. local VARIATION attribute — the regression this section exists to catch ──

test_section('local variation attribute (dropdown + regression check):');

// A first version of LocalAttributeResolver translated get_options() for
// EVERY local attribute, including one flagged is_variation. WooCommerce's
// own read_variation_attributes() matches each variation's stored selection
// (saved as the exact original text — confirmed from html-variation-admin.php's
// own dropdown value attribute) against that SAME options list with strict
// equality; once translated, nothing matched and the frontend dropdown
// silently showed zero options. This section is the regression test for
// that fix, not just new-feature coverage.
$swatchProduct = new WC_Product_Variable();
$swatchProduct->set_name('LINGOWP_TEST swatch product');
$swatchProduct->save();
$swatchProductId = $swatchProduct->get_id();

$swatchAttr = new WC_Product_Attribute();
$swatchAttr->set_id(0);
$swatchAttr->set_name('ZQWOOIT Size');
$swatchAttr->set_options(['ZQWOOIT Small', 'ZQWOOIT Medium', 'ZQWOOIT Large']);
$swatchAttr->set_variation(true);
$swatchAttr->set_visible(true);
$swatchProduct->set_attributes([$swatchAttr]);
$swatchProduct->save();

$swatchSlug = sanitize_title_with_dashes('ZQWOOIT Size'); // "zqwooit-size"

$swatchVariation = new WC_Product_Variation();
$swatchVariation->set_parent_id($swatchProductId);
// stored as the EXACT original text, not a slug — confirmed against WooCommerce's own admin template
$swatchVariation->set_attributes([$swatchSlug => 'ZQWOOIT Small']);
$swatchVariation->set_regular_price('10');
$swatchVariation->set_status('publish');
$swatchVariation->save();

LocalAttributeSync::refresh($swatchProductId);
$swatchMetaKeyHash = bin2hex(hash('sha256', LocalAttributeSync::META_KEY, true));
foreach (['name', 'value.0', 'value.1', 'value.2'] as $suffix) {
    $context = $swatchSlug . '.' . $suffix;
    $text    = $wpdb->get_var($wpdb->prepare(
        "SELECT original_text FROM {$MP} WHERE post_id = %d AND meta_key_hash = UNHEX(%s) AND context = %s",
        $swatchProductId, $swatchMetaKeyHash, $context
    ));
    $rowId = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$MP} WHERE post_id = %d AND meta_key_hash = UNHEX(%s) AND context = %s",
        $swatchProductId, $swatchMetaKeyHash, $context
    ));
    test_ok("discovered $context", $rowId !== null);
    test_insert($MP . '_translation', [
        'parent_id' => $rowId, 'lang_code' => $lang, 'translated_text' => 'LINGOWP_TEST traduit ' . $context,
        'status' => 2, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

wp_cache_flush();
$translatedSwatchProduct = wc_get_product($swatchProductId);
$possibleValues = $translatedSwatchProduct->get_variation_attributes();

test_ok(
    'REGRESSION: dropdown possible-values list stays non-empty under translation (was silently emptied before the fix)',
    ($possibleValues['ZQWOOIT Size'] ?? []) === ['ZQWOOIT Small']
);

ob_start();
wc_dropdown_variation_attribute_options([
    'options'   => $possibleValues['ZQWOOIT Size'] ?? [],
    'attribute' => 'ZQWOOIT Size',
    'product'   => $translatedSwatchProduct,
]);
$dropdownHtml = (string) ob_get_clean();
test_ok(
    'dropdown <option value=""> stays the ORIGINAL text (still matches the variation JSON)',
    strpos($dropdownHtml, 'value="ZQWOOIT Small"') !== false
);
test_ok(
    'dropdown DISPLAYED text is translated',
    strpos($dropdownHtml, '>LINGOWP_TEST traduit ' . $swatchSlug . '.value.0<') !== false
);

$available = $translatedSwatchProduct->get_available_variations();
$jsAttributes = $available[0]['attributes'] ?? [];
test_ok(
    'the JS-matching JSON (data-product_variations) keeps the ORIGINAL raw value untouched',
    in_array('ZQWOOIT Small', $jsAttributes, true)
);

ob_start();
wc_display_product_attributes($translatedSwatchProduct);
$swatchTabHtml = (string) ob_get_clean();
test_ok('tab shows the translated label for a variation-flagged local attribute', strpos($swatchTabHtml, 'LINGOWP_TEST traduit ' . $swatchSlug . '.name') !== false);
test_ok('tab shows the translated values for a variation-flagged local attribute', strpos($swatchTabHtml, 'LINGOWP_TEST traduit ' . $swatchSlug . '.value.0') !== false);

// reset the memoized statics so a re-run of this file in the same process is clean
$ctxProp->setValue(null, null);
$langProp->setValue(null, null);

// a request the plugin has no reason to touch must pass the data through untouched
$untouchedData = ['variation_description' => wc_format_content($sentinel)];
$ctxProp->setValue(null, new class extends \LingoWP\Resolution\RequestContext {
    public function shouldTranslate(): bool
    {
        return false;
    }
});
$result = VariationDescriptionResolver::translate($untouchedData, null, $variation);
test_ok('an inactive request leaves variation_description untouched', $result === $untouchedData);
$ctxProp->setValue(null, null);

test_done();

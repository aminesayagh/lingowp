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

// ── 5. WooCommerce attribute label — the core-owned table, real FK ──────────

test_section('WooCommerce attribute label (core table, real foreign key):');

$attrId2 = wc_create_attribute(['name' => 'ZQWOOIT Attr ' . wp_generate_password(4, false), 'slug' => 'zqwooit' . wp_generate_password(4, false)]);
test_ok('wc_create_attribute() succeeded', !is_wp_error($attrId2), is_wp_error($attrId2) ? $attrId2->get_error_message() : '');

$WAL = TranslationMemorySchema::wooAttributeLabelTable();
$WAL_T = $WAL . '_translation';

$labelRow = test_var("SELECT id FROM {$WAL} WHERE attribute_id = %d", $attrId2);
test_ok(
    'attribute label discovered automatically via the real woocommerce_attribute_added hook (no manual refresh() call)',
    $labelRow !== null
);

wc_update_attribute($attrId2, ['name' => 'ZQWOOIT Attr Updated', 'slug' => wc_attribute_taxonomy_slug(wc_attribute_taxonomy_name_by_id($attrId2))]);
$updatedFound = test_var(
    "SELECT id FROM {$WAL} WHERE attribute_id = %d AND original_text = %s",
    $attrId2, 'ZQWOOIT Attr Updated'
);
test_ok('label update discovered via the real woocommerce_attribute_updated hook', $updatedFound !== null);

$attrTranslated = 'LINGOWP_TEST ZQWOOIT Attr Traduit';
test_insert($WAL_T, [
    'original_hash'   => hash('sha256', \LingoWP\Shared\Text\StringNormalizer::normalize('ZQWOOIT Attr Updated'), true),
    'lang_code'       => $lang,
    'translated_text' => $attrTranslated,
    'status'          => 2,
    'created_at'      => $now,
    'updated_at'      => $now,
]);

$attrTaxonomy = wc_attribute_taxonomy_name_by_id($attrId2);
$attrRenderResult = AttributeLabelResolver::translate('ZQWOOIT Attr Updated', $attrTaxonomy);
test_ok('render swaps in the translated attribute label', $attrRenderResult === $attrTranslated);

$localAttrResult = AttributeLabelResolver::translate('Some Local Attribute Label', 'not_a_real_taxonomy_name');
test_ok('a LOCAL (non-taxonomy) product attribute label is left untouched', $localAttrResult === 'Some Local Attribute Label');

// Through WpDbSourceRepository, the same object CollectionBoard actually holds
// — NOT a bare WpDbBoardQueries instance. A first version of this test called
// WpDbBoardQueries directly and passed even though WpDbSourceRepository was
// missing the delegating wrapper CollectionBoard actually calls (caught only
// by a separate live CollectionBoard::buildCollections() check, not this
// test) — real production code path, not a shortcut, from here on.
$repo = \LingoWP\Database\Repository\WpDbSourceRepository::instance();
$attrSummary = $repo->wooAttributeLabelCollectionSummary($lang);
test_ok('board summary (via WpDbSourceRepository, CollectionBoard\'s own path) sees the translated attribute label', $attrSummary['total'] >= 1 && $attrSummary['translated'] >= 1);

$reg = new \LingoWP\Language\Infrastructure\OptionLanguageRegistry();
$collectionBoard = new \LingoWP\Board\CollectionBoard($repo, $reg);
$collections = $collectionBoard->buildCollections($lang, '');
$attrCollection = null;
foreach ($collections as $c) {
    if ($c['id'] === 'woo:attribute_labels') {
        $attrCollection = $c;
    }
}
test_ok('CollectionBoard::buildCollections() includes the attribute-labels card', $attrCollection !== null && $attrCollection['total'] >= 1);

wc_delete_attribute($attrId2);
$afterAttrDelete = test_var("SELECT id FROM {$WAL} WHERE attribute_id = %d", $attrId2);
test_ok('deleting the attribute prunes its source row', $afterAttrDelete === null);

// ── 6. WooCommerce tax rate name — same shape, same real FK ─────────────────

test_section('WooCommerce tax rate name (core table, real foreign key):');

$rateId2 = WC_Tax::_insert_tax_rate([
    'tax_rate_country' => '',
    'tax_rate_state'   => '',
    'tax_rate'         => '20.0000',
    'tax_rate_name'    => 'ZQWOOIT VAT',
    'tax_rate_class'   => '',
]);

$WTR = TranslationMemorySchema::wooTaxRateTable();
$WTR_T = $WTR . '_translation';

$rateRow = test_var("SELECT id FROM {$WTR} WHERE tax_rate_id = %d", $rateId2);
test_ok('tax rate name discovered automatically via the real woocommerce_tax_rate_added hook', $rateRow !== null);

WC_Tax::_update_tax_rate($rateId2, ['tax_rate_name' => 'ZQWOOIT VAT Updated', 'tax_rate' => '20.0000', 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate_class' => '']);
$rateUpdatedFound = test_var(
    "SELECT id FROM {$WTR} WHERE tax_rate_id = %d AND original_text = %s",
    $rateId2, 'ZQWOOIT VAT Updated'
);
test_ok('name update discovered via the real woocommerce_tax_rate_updated hook', $rateUpdatedFound !== null);

$rateTranslated = 'LINGOWP_TEST ZQWOOIT TVA Traduit';
test_insert($WTR_T, [
    'original_hash'   => hash('sha256', \LingoWP\Shared\Text\StringNormalizer::normalize('ZQWOOIT VAT Updated'), true),
    'lang_code'       => $lang,
    'translated_text' => $rateTranslated,
    'status'          => 2,
    'created_at'      => $now,
    'updated_at'      => $now,
]);

$rateRenderResult = TaxRateLabelResolver::translate('ZQWOOIT VAT Updated', (string) $rateId2);
test_ok('render swaps in the translated tax rate name', $rateRenderResult === $rateTranslated);

$zeroRatedResult = TaxRateLabelResolver::translate('ZQWOOIT VAT Updated', 'zero-rated');
test_ok('a non-numeric sentinel key (e.g. "zero-rated") is left untouched', $zeroRatedResult === 'ZQWOOIT VAT Updated');

$rateSummary = $repo->wooTaxRateCollectionSummary($lang);
test_ok('board summary (via WpDbSourceRepository) sees the translated tax rate name', $rateSummary['total'] >= 1 && $rateSummary['translated'] >= 1);

$collections2 = $collectionBoard->buildCollections($lang, '');
$rateCollection = null;
foreach ($collections2 as $c) {
    if ($c['id'] === 'woo:tax_rates') {
        $rateCollection = $c;
    }
}
test_ok('CollectionBoard::buildCollections() includes the tax-rates card', $rateCollection !== null && $rateCollection['total'] >= 1);

$rateRefs = $collectionBoard->collectionRefs('woo:tax_rates', $lang);
test_ok('CollectionBoard::collectionRefs() returns woo_tax_rate SourceRefs', count($rateRefs) >= 1 && $rateRefs[0]->kind === 'woo_tax_rate');

WC_Tax::_delete_tax_rate($rateId2);
$afterRateDelete = test_var("SELECT id FROM {$WTR} WHERE tax_rate_id = %d", $rateId2);
test_ok('deleting the tax rate prunes its source row', $afterRateDelete === null);

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

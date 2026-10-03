<?php

namespace LingoWP\Shared\Source;

/**
 * The single source of truth for which post-meta keys are translatable
 * (data pipeline §1 row 8, D25 — allow-list, never deny-list).
 *
 * Discovery (StructuredSourceScanner) and render resolution
 * (StructuredRenderResolver) MUST agree on this list, or a key discovered on
 * save would never resolve on render. Sites extend it via the
 * `lingowp_translatable_meta_keys` filter.
 */
final class TranslatableMetaKeys
{
    /** Media ALT is allow-listed by default; it rides the attachment (type-2) path. */
    public const ATTACHMENT_ALT = '_wp_attachment_image_alt';

    /**
     * A STATIC UNION across every plugin that extends this list — never
     * narrowed to whichever one is active. reconcilePost() reconciles the
     * whole post meta scope, so a key dropping out of this list on a save
     * deletes its rows and cascades to their translations. Deactivating a
     * plugin does not delete its post meta, so keeping the key here costs
     * nothing and preserves the translations.
     *
     * Yoast/Rank Math/SEOPress's own keys used to live here directly; they now
     * come from the lingowp-seo add-on via this same filter (see keys()) —
     * core no longer hardcodes any third-party SEO plugin's key names.
     */
    private const DEFAULTS = [
        '_wc_product_short_description',
    ];

    /**
     * Which SEO field a key represents, for dashboard display only — title vs.
     * description vs. keyword. Deliberately separate from
     * SeoTemplateVariables::DESCRIPTORS, which answers a different question
     * (does this key have replacement-variable syntax to guard); folding
     * keyword keys in there would wrongly turn on that guard for a plain
     * phrase with no template variables at all.
     *
     * Empty in core by design — Yoast/Rank Math/SEOPress's own mapping now
     * comes from the lingowp-seo add-on via the lingowp_seo_field_types
     * filter (see seoFieldType()).
     */
    private const SEO_FIELD_TYPES = [];

    private function __construct() {}

    /**
     * 'title' | 'description' | 'keyword' | 'og_title' | 'og_description' |
     * 'twitter_title' | 'twitter_description' | null — unknown/non-SEO keys
     * get null.
     */
    public static function seoFieldType(string $key): ?string
    {
        $types = function_exists('apply_filters')
            ? apply_filters('lingowp_seo_field_types', self::SEO_FIELD_TYPES)
            : self::SEO_FIELD_TYPES;

        return (is_array($types) ? $types : self::SEO_FIELD_TYPES)[$key] ?? null;
    }

    /**
     * @return string[] Allow-listed meta keys (slug-like keys excluded).
     */
    public static function keys(): array
    {
        $keys = function_exists('apply_filters')
            ? apply_filters('lingowp_translatable_meta_keys', self::DEFAULTS)
            : self::DEFAULTS;

        return array_values(array_filter(
            array_map('strval', is_array($keys) ? $keys : self::DEFAULTS),
            static fn(string $key): bool => $key !== '' && !self::isSlugKey($key)
        ));
    }

    public static function isSlugKey(string $key): bool
    {
        $key = strtolower(trim($key));

        return in_array($key, ['slug', '_slug', 'post_name', '_wp_old_slug'], true)
            || str_ends_with($key, '_slug');
    }
}

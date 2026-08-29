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
     * A STATIC UNION across SEO plugins — never narrowed to whichever one is
     * active. reconcilePost() reconciles the whole post meta scope, so a key
     * dropping out of this list on a save deletes its rows and cascades to their
     * translations. Deactivating a plugin does not delete its post meta, so
     * keeping the key here costs nothing and preserves the translations.
     */
    private const DEFAULTS = [
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_focuskw',
        '_yoast_wpseo_opengraph-title',
        '_yoast_wpseo_opengraph-description',
        '_yoast_wpseo_twitter-title',
        '_yoast_wpseo_twitter-description',
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
        'rank_math_facebook_title',
        'rank_math_facebook_description',
        'rank_math_twitter_title',
        'rank_math_twitter_description',
        '_seopress_titles_title',
        '_seopress_titles_desc',
        '_seopress_analysis_target_kw',
        '_seopress_social_fb_title',
        '_seopress_social_fb_desc',
        '_seopress_social_twitter_title',
        '_seopress_social_twitter_desc',
        '_wc_product_short_description',
    ];

    /**
     * Which SEO field a key represents, for dashboard display only — title vs.
     * description vs. keyword. Deliberately separate from
     * SeoTemplateVariables::DESCRIPTORS, which answers a different question
     * (does this key have replacement-variable syntax to guard); folding
     * keyword keys in there would wrongly turn on that guard for a plain
     * phrase with no template variables at all.
     */
    private const SEO_FIELD_TYPES = [
        '_yoast_wpseo_title'                => 'title',
        '_yoast_wpseo_metadesc'              => 'description',
        '_yoast_wpseo_focuskw'               => 'keyword',
        '_yoast_wpseo_opengraph-title'       => 'og_title',
        '_yoast_wpseo_opengraph-description' => 'og_description',
        '_yoast_wpseo_twitter-title'         => 'twitter_title',
        '_yoast_wpseo_twitter-description'   => 'twitter_description',
        'rank_math_title'                   => 'title',
        'rank_math_description'             => 'description',
        'rank_math_focus_keyword'           => 'keyword',
        'rank_math_facebook_title'          => 'og_title',
        'rank_math_facebook_description'    => 'og_description',
        'rank_math_twitter_title'           => 'twitter_title',
        'rank_math_twitter_description'     => 'twitter_description',
        '_seopress_titles_title'            => 'title',
        '_seopress_titles_desc'             => 'description',
        '_seopress_analysis_target_kw'      => 'keyword',
        '_seopress_social_fb_title'         => 'og_title',
        '_seopress_social_fb_desc'          => 'og_description',
        '_seopress_social_twitter_title'    => 'twitter_title',
        '_seopress_social_twitter_desc'     => 'twitter_description',
    ];

    private function __construct() {}

    /**
     * 'title' | 'description' | 'keyword' | 'og_title' | 'og_description' |
     * 'twitter_title' | 'twitter_description' | null — unknown/non-SEO keys
     * get null.
     */
    public static function seoFieldType(string $key): ?string
    {
        return self::SEO_FIELD_TYPES[$key] ?? null;
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

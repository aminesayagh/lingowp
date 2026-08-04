<?php

namespace TransLangs\Core\Source\Type;

/**
 * The single source of truth for which post-meta keys are translatable
 * (data pipeline §1 row 8, D25 — allow-list, never deny-list).
 *
 * Discovery (StructuredSourceScanner) and render resolution
 * (StructuredRenderResolver) MUST agree on this list, or a key discovered on
 * save would never resolve on render. Sites extend it via the
 * `translangs_translatable_meta_keys` filter.
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
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
        '_seopress_titles_title',
        '_seopress_titles_desc',
        '_seopress_analysis_target_kw',
        '_wc_product_short_description',
    ];

    private function __construct() {}

    /**
     * @return string[] Allow-listed meta keys (slug-like keys excluded).
     */
    public static function keys(): array
    {
        $keys = function_exists('apply_filters')
            ? apply_filters('translangs_translatable_meta_keys', self::DEFAULTS)
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

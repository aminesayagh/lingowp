<?php

namespace LingoWP\Shared\Source;

final class TranslatableMetaKeys
{
    public const ATTACHMENT_ALT = '_wp_attachment_image_alt';

    private const DEFAULTS = [
        '_wc_product_short_description',
    ];

    private const SEO_FIELD_TYPES = [];

    private function __construct() {}

    public static function seoFieldType(string $key): ?string
    {
        $types = function_exists('apply_filters')
            ? apply_filters('lingowp_seo_field_types', self::SEO_FIELD_TYPES)
            : self::SEO_FIELD_TYPES;

        return (is_array($types) ? $types : self::SEO_FIELD_TYPES)[$key] ?? null;
    }

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

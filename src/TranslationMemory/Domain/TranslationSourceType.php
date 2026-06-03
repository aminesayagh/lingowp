<?php

namespace TransLangs\TranslationMemory\Domain;

final class TranslationSourceType
{
    /**
     * Fixed source types only.
     *
     * Custom post types use their runtime post type name as source_type, so
     * they cannot be listed here as a static config value.
     */
    public const MEDIA = 'media';
    public const TAXONOMY = 'taxonomy';
    public const MENU = 'menu';
    public const WIDGET = 'widget';
    public const PAGE = 'page';
    public const POST = 'post';
    public const PRODUCT = 'product';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::MEDIA,
            self::TAXONOMY,
            self::MENU,
            self::WIDGET,
            self::PAGE,
            self::POST,
            self::PRODUCT,
        ];
    }

    /**
     * Returns true if the type is one of the fixed constants defined here.
     *
     * Custom post types use their runtime post type name, so they will return
     * false — that is expected and correct.
     */
    public static function isFixed(string $sourceType): bool
    {
        return in_array($sourceType, self::all(), true);
    }

    /**
     * Returns true if the type represents a post-like entity whose structured
     * fields are post_title / post_content / post_excerpt.
     *
     * Returns true for page, post, product, and any custom post type (runtime
     * name). Returns false for media, taxonomy, menu, and widget.
     */
    public static function isPostLike(string $sourceType): bool
    {
        return !in_array($sourceType, [
            self::MEDIA,
            self::TAXONOMY,
            self::MENU,
            self::WIDGET,
        ], true);
    }
}

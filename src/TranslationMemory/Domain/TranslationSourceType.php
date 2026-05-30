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
}

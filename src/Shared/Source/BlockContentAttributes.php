<?php

namespace LingoWP\Shared\Source;

final class BlockContentAttributes
{
    private function __construct() {}

    private static array $dynamicCache = [];

    private static array $contentKeysCache = [];

    public static function isDynamic(string $blockName): bool
    {
        if (isset(self::$dynamicCache[$blockName])) {
            return self::$dynamicCache[$blockName];
        }

        $type = self::registered($blockName);

        return self::$dynamicCache[$blockName] = $type instanceof \WP_Block_Type && $type->is_dynamic();
    }

    public static function contentKeys(string $blockName): array
    {
        if (isset(self::$contentKeysCache[$blockName])) {
            return self::$contentKeysCache[$blockName];
        }

        $type = self::registered($blockName);
        $keys = [];

        if ($type instanceof \WP_Block_Type && is_array($type->attributes)) {
            foreach ($type->attributes as $key => $schema) {
                if (($schema['type'] ?? null) === 'string' && ($schema['role'] ?? null) === 'content') {
                    $keys[] = (string) $key;
                }
            }
        }

        return self::$contentKeysCache[$blockName] = $keys;
    }

    private static function registered(string $blockName): ?\WP_Block_Type
    {
        if (!class_exists(\WP_Block_Type_Registry::class)) {
            return null;
        }

        return \WP_Block_Type_Registry::get_instance()->get_registered($blockName);
    }
}

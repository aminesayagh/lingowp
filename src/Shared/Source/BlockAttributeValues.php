<?php

namespace LingoWP\Shared\Source;

use LingoWP\Shared\Text\TranslatableText;

final class BlockAttributeValues
{
    private function __construct() {}

    public static function extract(string $postContent): array
    {
        if (!function_exists('parse_blocks') || trim($postContent) === '') {
            return [];
        }

        $texts = [];
        self::walk(parse_blocks($postContent), $texts);

        return $texts;
    }

    private static function walk(array $blocks, array &$texts): void
    {
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;

            if (is_string($name) && $name !== '' && BlockContentAttributes::isDynamic($name)) {
                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                foreach (BlockContentAttributes::contentKeys($name) as $key) {
                    $value = $attrs[$key] ?? null;
                    if (is_string($value) && TranslatableText::isTranslatable($value)) {
                        $texts[] = $value;
                    }
                }
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                self::walk($block['innerBlocks'], $texts);
            }
        }
    }
}

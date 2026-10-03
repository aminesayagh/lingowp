<?php

namespace LingoWP\Shared\Source;

use LingoWP\Shared\Text\TranslatableText;

/**
 * Flattens a post_content string into the translatable text values stored
 * only in a dynamic block's own settings (data pipeline follow-up to §1 row 2
 * — see BlockContentAttributes' class doc for the full reasoning). Mirrors
 * PostMetaValuePaths's role: a focused, pure(ish) extraction class the
 * scanner calls, not folded into it.
 *
 * Walks parse_blocks()'s own structure (WordPress core — no custom comment
 * parsing needed), including every nested inner block, so a Navigation Link
 * three levels deep inside Group > Navigation is found the same as a
 * top-level one.
 */
final class BlockAttributeValues
{
    private function __construct() {}

    /**
     * @return list<string> translatable content-attribute values found
     *   anywhere in $postContent, document order, depth-first
     */
    public static function extract(string $postContent): array
    {
        if (!function_exists('parse_blocks') || trim($postContent) === '') {
            return [];
        }

        $texts = [];
        self::walk(parse_blocks($postContent), $texts);

        return $texts;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param list<string>                     $texts
     */
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

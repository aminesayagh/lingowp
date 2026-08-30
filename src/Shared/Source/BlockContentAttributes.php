<?php

namespace LingoWP\Shared\Source;

/**
 * Which of a Gutenberg block type's own settings hold real, visitor-facing
 * content — for blocks (Search, Navigation Link, Post Terms, …) that store
 * their text ONLY inside the block's own JSON settings, never in the page's
 * visible markup, so the DOM-based scanner (HtmlUnitExtractor) never sees it.
 *
 * Layer-neutral, same reasoning as TranslatableMetaKeys/ScannablePostStatuses:
 * discovery (BlockAttributeValues) and render (StructuredRenderResolver)
 * MUST agree on the same answer, or a value discovered on save would never
 * resolve on render.
 *
 * Both questions are answered from WordPress's OWN block type registry —
 * every registered block declares an attribute schema (block.json), which can
 * mark an attribute `"role": "content"` (real content, not a layout/style
 * setting) and a block registers a render_callback only when it needs one
 * (a block whose text lives only in settings, with no static HTML of its own,
 * can only ever produce output through such a callback). Confirmed directly
 * against WordPress core's own bundled blocks: Search's label/placeholder/
 * buttonText and Post Terms' prefix/suffix — the fields found missing — all
 * carry the role=content marker.
 *
 * `role: content` is not a perfect prose signal on its own (Navigation Link's
 * own `url` carries it too) — TranslatableText::isTranslatable() is still the
 * real content filter, applied by the caller on whatever value comes back
 * from contentKeys(), the same second-stage gate used everywhere else in this
 * codebase.
 */
final class BlockContentAttributes
{
    private function __construct() {}

    /** @var array<string, bool> memoized per block name for the request */
    private static array $dynamicCache = [];

    /** @var array<string, string[]> memoized per block name for the request */
    private static array $contentKeysCache = [];

    /**
     * Whether $blockName re-renders its output from its own attrs at request
     * time. A purely static block (Paragraph, Heading) reproduces its saved
     * HTML verbatim and ignores attrs entirely at render — mutating attrs for
     * one would be a silent no-op, so both discovery and render skip anything
     * that isn't dynamic.
     */
    public static function isDynamic(string $blockName): bool
    {
        if (isset(self::$dynamicCache[$blockName])) {
            return self::$dynamicCache[$blockName];
        }

        $type = self::registered($blockName);

        return self::$dynamicCache[$blockName] = $type instanceof \WP_Block_Type && $type->is_dynamic();
    }

    /** @return string[] attribute keys this block type marks role=content and type=string */
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

<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * Vocabulary of valid context values per source type.
 *
 * Context format rules by source type:
 *
 *   media    → one of the four static field constants (alt/title/caption/description)
 *   menu     → one of the three static field constants (title/attr_title/description)
 *   taxonomy → dynamic "{taxonomy}.name" or "{taxonomy}.description" — use factory methods
 *   page     → post_title / post_content / post_excerpt  (source_id > 0)
 *              or DOM/archetype selector                   (source_id = 0)
 *   post     → post_title / post_content / post_excerpt  (source_id > 0)
 *              or DOM/archetype selector                   (source_id = 0)
 *   product  → post_title / post_content / post_excerpt / purchase_note /
 *              external_button_text / attribute.{key}.name /
 *              attribute.{key}.option.{n}                 (source_id > 0)
 *              or DOM/archetype selector                   (source_id = 0)
 *   {cpt}    → post_title / post_content / post_excerpt  (source_id > 0)
 *              or DOM/archetype selector                   (source_id = 0)
 *   widget   → widget_title / widget_text                (source_id > 0)
 *
 * Context kind:
 *   KIND_FIELD    → context is an entity field name; source_id identifies the entity
 *   KIND_SELECTOR → context is a DOM path or archetype key; source_id = 0
 */
final class TranslationSourceContext
{
    // ── Context kinds ──────────────────────────────────────────────────────────

    /** Structured entity field (post_title, alt, category.name, …). */
    public const KIND_FIELD = 'field';

    /**
     * DOM path or archetype key emitted by the HTML parser or archetype scanner.
     * Always paired with source_id = 0.
     */
    public const KIND_SELECTOR = 'selector';

    // ── Media field contexts ───────────────────────────────────────────────────

    public const MEDIA_ALT         = 'alt';
    public const MEDIA_TITLE       = 'title';
    public const MEDIA_CAPTION     = 'caption';
    public const MEDIA_DESCRIPTION = 'description';

    // ── Post-type field contexts (page / post / product / any CPT) ─────────────

    public const POST_TITLE   = 'post_title';
    public const POST_CONTENT = 'post_content';
    public const POST_EXCERPT = 'post_excerpt';

    // ── WooCommerce product-specific field contexts ────────────────────────────

    public const PRODUCT_PURCHASE_NOTE        = 'purchase_note';
    public const PRODUCT_EXTERNAL_BUTTON_TEXT = 'external_button_text';

    // ── Menu item field contexts ───────────────────────────────────────────────

    public const MENU_TITLE       = 'title';
    public const MENU_ATTR_TITLE  = 'attr_title';
    public const MENU_DESCRIPTION = 'description';

    // ── Widget field contexts ──────────────────────────────────────────────────

    public const WIDGET_TITLE = 'widget_title';
    public const WIDGET_TEXT  = 'widget_text';

    // ── Dynamic context factories ──────────────────────────────────────────────

    /** Returns "{taxonomy}.name" — e.g. "category.name", "product_cat.name". */
    public static function taxonomyName(string $taxonomy): string
    {
        return $taxonomy . '.name';
    }

    /** Returns "{taxonomy}.description" — e.g. "category.description". */
    public static function taxonomyDescription(string $taxonomy): string
    {
        return $taxonomy . '.description';
    }

    /** Returns "attribute.{key}.name" for a custom product attribute. */
    public static function attributeName(string $sanitizedKey): string
    {
        return 'attribute.' . $sanitizedKey . '.name';
    }

    /** Returns "attribute.{key}.option.{index}" for a custom attribute option. */
    public static function attributeOption(string $sanitizedKey, int $index): string
    {
        return 'attribute.' . $sanitizedKey . '.option.' . $index;
    }

    // ── Context kind resolver ──────────────────────────────────────────────────

    /**
     * Returns the context kind for a given source_type + source_id combination.
     *
     * source_id = 0 always means rendered/template text regardless of source_type;
     * the context is a DOM selector or archetype path (KIND_SELECTOR).
     *
     * source_id > 0 always means a structured entity field (KIND_FIELD).
     */
    public static function kindFor(string $sourceType, int $sourceId): string
    {
        return $sourceId === 0 ? self::KIND_SELECTOR : self::KIND_FIELD;
    }

    // ── Known static context list per source type ──────────────────────────────

    /**
     * Returns all statically known context values for a source_type.
     *
     * Dynamic contexts (taxonomy, product attributes) are not enumerable here;
     * use the factory methods instead.
     *
     * @return string[]
     */
    public static function knownFor(string $sourceType): array
    {
        return match ($sourceType) {
            TranslationSourceType::MEDIA => [
                self::MEDIA_ALT,
                self::MEDIA_TITLE,
                self::MEDIA_CAPTION,
                self::MEDIA_DESCRIPTION,
            ],
            TranslationSourceType::PAGE,
            TranslationSourceType::POST => [
                self::POST_TITLE,
                self::POST_CONTENT,
                self::POST_EXCERPT,
            ],
            TranslationSourceType::PRODUCT => [
                self::POST_TITLE,
                self::POST_CONTENT,
                self::POST_EXCERPT,
                self::PRODUCT_PURCHASE_NOTE,
                self::PRODUCT_EXTERNAL_BUTTON_TEXT,
            ],
            TranslationSourceType::MENU => [
                self::MENU_TITLE,
                self::MENU_ATTR_TITLE,
                self::MENU_DESCRIPTION,
            ],
            TranslationSourceType::WIDGET => [
                self::WIDGET_TITLE,
                self::WIDGET_TEXT,
            ],
            // TAXONOMY and CPTs have dynamic contexts — nothing to enumerate statically
            default => [],
        };
    }
}

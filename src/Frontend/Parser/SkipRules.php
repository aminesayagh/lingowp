<?php

namespace TransLangs\Frontend\Parser;

/**
 * Centralized rules for what NOT to translate.
 *
 * Kept separate so every parser driver shares one definition, and so the list
 * is trivially testable and extensible (filters can be added later).
 */
class SkipRules
{
    /**
     * Tags whose entire subtree must be ignored. Lowercase.
     *
     * @var string[]
     */
    private const SKIP_TAGS = [
        'script', 'style', 'code', 'pre', 'noscript', 'svg', 'canvas',
        'template', 'textarea', 'iframe', 'object', 'embed',
    ];

    /**
     * Class names that mark an element (and its subtree) as non-translatable.
     *
     * @var string[]
     */
    private const SKIP_CLASSES = [
        'notranslate', 'wp-admin-bar',
    ];

    /**
     * Element ids to skip entirely.
     *
     * @var string[]
     */
    private const SKIP_IDS = [
        'wpadminbar',
    ];

    /**
     * Which attributes are translatable, by tag. "*" applies to any tag.
     *
     * @var array<string, string[]>
     */
    private const TRANSLATABLE_ATTRIBUTES = [
        '*'        => ['title', 'aria-label'],
        'img'      => ['alt'],
        'input'    => ['placeholder', 'value'],
        'textarea' => ['placeholder'],
        'a'        => ['title'],
        'meta'     => ['content'], // gated further by isTranslatableMeta()
    ];

    public function isSkippedTag(string $tag): bool
    {
        return in_array(strtolower($tag), self::SKIP_TAGS, true);
    }

    /**
     * @param string[] $classList
     */
    public function isSkippedByClass(array $classList): bool
    {
        foreach ($classList as $class) {
            if (in_array(strtolower($class), self::SKIP_CLASSES, true)) {
                return true;
            }
        }

        return false;
    }

    public function isSkippedById(?string $id): bool
    {
        return $id !== null && in_array(strtolower($id), self::SKIP_IDS, true);
    }

    /**
     * The opt-out attribute, e.g. <div data-no-translate>...</div>.
     */
    public function hasNoTranslateAttr(bool $present): bool
    {
        return $present;
    }

    /**
     * Attribute names worth translating for a given tag.
     *
     * @return string[]
     */
    public function translatableAttributes(string $tag): array
    {
        $tag = strtolower($tag);
        $attrs = self::TRANSLATABLE_ATTRIBUTES['*'];

        if (isset(self::TRANSLATABLE_ATTRIBUTES[$tag])) {
            $attrs = array_merge($attrs, self::TRANSLATABLE_ATTRIBUTES[$tag]);
        }

        return array_values(array_unique($attrs));
    }

    /**
     * Only specific <meta> names/properties carry translatable text.
     */
    public function isTranslatableMeta(?string $name, ?string $property): bool
    {
        $name     = strtolower((string) $name);
        $property = strtolower((string) $property);

        $okNames = ['description'];
        $okProps = ['og:title', 'og:description', 'og:site_name', 'twitter:title', 'twitter:description'];

        return in_array($name, $okNames, true) || in_array($property, $okProps, true);
    }

    /**
     * Reject text that is not worth translating (whitespace, numbers, symbols).
     */
    public function isTranslatableText(string $text): bool
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return false;
        }

        // Pure numbers, currency, dates, percentages, short symbol runs.
        if (preg_match('/^[\p{N}\p{P}\p{S}\s]+$/u', $trimmed)) {
            return false;
        }

        // Must contain at least one letter.
        if (!preg_match('/\p{L}/u', $trimmed)) {
            return false;
        }

        return true;
    }
}
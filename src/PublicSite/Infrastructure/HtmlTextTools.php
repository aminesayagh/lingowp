<?php

namespace TransLangs\PublicSite\Infrastructure;

use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;

final class HtmlTextTools
{
    private const SKIPPED_ANCESTORS = [
        'script'   => true,
        'style'    => true,
        'noscript' => true,
        'svg'      => true,
        'math'     => true,
    ];

    private const LANDMARKS = [
        'header'  => true,
        'nav'     => true,
        'main'    => true,
        'footer'  => true,
        'aside'   => true,
        'article' => true,
        'section' => true,
        'form'    => true,
    ];

    private const WORDPRESS_ADMIN_UI_IDS = [
        'wpadminbar' => true,
    ];

    private const WORDPRESS_ADMIN_UI_CLASSES = [
        'ab-top-menu'       => true,
        'ab-sub-wrapper'    => true,
        'ab-item'           => true,
        'screen-reader-shortcut' => true,
    ];

    public function normalizedText(string $text): string
    {
        return StringNormalizer::normalize($text);
    }

    public function originalHashHex(string $normalizedText): string
    {
        return bin2hex(StringHashGenerator::hashNormalized($normalizedText));
    }

    /**
     * @param string[] $tags
     */
    public function shouldSkipPath(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (isset(self::SKIPPED_ANCESTORS[strtolower($tag)])) {
                return true;
            }
        }

        return false;
    }

    public function containsWordPressAdminUi(string $html): bool
    {
        return preg_match('/\\bid\\s*=\\s*([\\\'"])wpadminbar\\1/i', $html) === 1;
    }

    public function shouldSkipElementByAttributes(string $id, string $class): bool
    {
        $id = strtolower(trim($id));

        // Check ID
        if ($id !== '' && isset(self::WORDPRESS_ADMIN_UI_IDS[$id])) {
            return true;
        }

        // Check classes
        $class = strtolower(trim($class));
        if ($class !== '') {
            $classes = preg_split('/\s+/', $class, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($classes as $cls) {
                if (isset(self::WORDPRESS_ADMIN_UI_CLASSES[$cls])) {
                    return true;
                }
            }
        }

        return false;
    }

    public function replacePreservingEdgeWhitespace(string $original, string $translated): string
    {
        if (preg_match('/\\A(\\s*).+?(\\s*)\\z/su', $original, $matches) !== 1) {
            return $translated;
        }

        return $matches[1] . $translated . $matches[2];
    }

    /**
     * @param string[] $tags Root-to-leaf tag path.
     */
    public function structuralSelector(array $tags): string
    {
        $tags = array_values(array_filter(
            array_map(static fn(string $tag): string => strtolower($tag), $tags),
            static fn(string $tag): bool => $tag !== '' && $tag !== 'html' && $tag !== 'body'
        ));

        if ($tags === []) {
            return '';
        }

        $landmarkIndex = null;
        foreach ($tags as $index => $tag) {
            if (isset(self::LANDMARKS[$tag])) {
                $landmarkIndex = $index;
            }
        }

        if ($landmarkIndex === null) {
            return implode('>', array_slice($tags, -2));
        }

        $selector = [$tags[$landmarkIndex]];
        $next     = $tags[$landmarkIndex + 1] ?? null;
        $leaf     = $tags[count($tags) - 1];

        if ($next !== null && $next !== $leaf) {
            $selector[] = $next;
        }

        if ($leaf !== $selector[count($selector) - 1]) {
            $selector[] = $leaf;
        }

        return implode('>', $selector);
    }
}

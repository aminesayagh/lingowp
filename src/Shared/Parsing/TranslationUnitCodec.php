<?php

namespace LingoWP\Shared\Parsing;

use LingoWP\Shared\Text\StringNormalizer;

final class TranslationUnitCodec
{
    private const VOID = [
        'br' => true, 'wbr' => true, 'img' => true, 'hr' => true, 'input' => true,
        'area' => true, 'col' => true, 'embed' => true, 'source' => true, 'track' => true,
    ];

    public function encode(\DOMElement $block, bool $preserveWhitespace = false): array
    {
        $counter = 0;
        $tags    = [];
        $unit    = $this->tidy($this->walk($block, $counter, $tags), $preserveWhitespace);
        $strip   = $this->stripWrapper($unit);

        if ($strip === null) {
            return ['unit' => $unit, 'tags' => $tags, 'wrap' => null];
        }

        return ['unit' => $strip['key'], 'tags' => $tags, 'wrap' => [$strip['prefix'], $strip['suffix']]];
    }

    public function rewrap(string $translatedUnit, array $encoded): string
    {
        if (empty($encoded['wrap'])) {
            return $translatedUnit;
        }

        [$prefix, $suffix] = $encoded['wrap'];

        return $prefix . $translatedUnit . $suffix;
    }

    private function stripWrapper(string $unit): ?array
    {
        $prefix = '';
        $suffix = '';
        $inner  = $unit;

        while (preg_match('/^\{(\d+)\}(.*)\{\/\1\}$/s', $inner, $m)) {
            $prefix .= '{' . $m[1] . '}';
            $suffix  = '{/' . $m[1] . '}' . $suffix;
            $inner   = $m[2];
        }

        $inner = trim($inner);
        if ($prefix === '' || $inner === '' || preg_match('/[{}]/', $inner)) {
            return null;
        }

        return ['key' => $inner, 'prefix' => $prefix, 'suffix' => $suffix];
    }

    private function tidy(string $unit, bool $preserveWhitespace = false): string
    {
        if ($preserveWhitespace) {
            return trim($unit);
        }

        return trim((string) preg_replace('/\s+/', ' ', $unit));
    }

    public function normalizeUnit(string $unit): string
    {
        return StringNormalizer::normalize($unit);
    }

    public function decode(string $translatedUnit, array $tags): string
    {
        $html = htmlspecialchars($translatedUnit, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ($tags as $n => $tag) {
            $html = str_replace('{' . $n . '/}', $tag['open'], $html);
            $html = str_replace('{' . $n . '}', $tag['open'], $html);
            $html = str_replace('{/' . $n . '}', $tag['close'], $html);
        }

        return $html;
    }

    private function walk(\DOMNode $node, int &$counter, array &$tags): string
    {
        $unit = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $unit .= $child->nodeValue;
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            $n    = ++$counter;
            $name = strtolower($child->tagName);
            $open = '<' . $name . $this->attributes($child);

            if (isset(self::VOID[$name])) {
                $tags[$n] = ['open' => $open . ' />', 'close' => ''];
                $unit    .= '{' . $n . '/}';
                continue;
            }

            $tags[$n] = ['open' => $open . '>', 'close' => '</' . $name . '>'];
            $unit    .= '{' . $n . '}' . $this->walk($child, $counter, $tags) . '{/' . $n . '}';
        }

        return $unit;
    }

    private function attributes(\DOMElement $element): string
    {
        $out = '';

        foreach ($element->attributes as $attr) {
            $out .= ' ' . $attr->nodeName
                . '="' . htmlspecialchars((string) $attr->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        }

        return $out;
    }
}

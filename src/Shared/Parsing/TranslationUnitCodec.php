<?php

namespace TransLangs\Shared\Parsing;

use TransLangs\Shared\Text\StringNormalizer;

/**
 * The placeholder chokepoint for type-5 composed units (parsing spec §5).
 *
 * A translation unit is one block element. Its inline descendants are flattened
 * into reversible numbered placeholders so the whole sentence translates as one
 * string with word order free and tags preserved:
 *
 *     <p>See <a href="/x"><strong>this</strong></a> now</p>
 *       encode → "See {1}{2}this{/2}{/1} now"   (tags[1]=a, tags[2]=strong)
 *       decode(translated, tags) → "<a href=\"/x\"><strong>…</strong></a>"
 *
 * Determinism is mandatory (hash drift = silent match failure): numbering is
 * strict document order, and the hash is taken over the unit string only
 * (text + `{n}` structure) — never attributes (P2), which live in the tag map
 * for rebuild and are derived live at render (P3).
 */
final class TranslationUnitCodec
{
    /** HTML void elements — emitted as a single self-closing placeholder `{n/}`. */
    private const VOID = [
        'br' => true, 'wbr' => true, 'img' => true, 'hr' => true, 'input' => true,
        'area' => true, 'col' => true, 'embed' => true, 'source' => true, 'track' => true,
    ];

    /**
     * @return array{unit: string, tags: array<int, array{open: string, close: string}>, wrap: ?array{0: string, 1: string}}
     */
    public function encode(\DOMElement $block): array
    {
        $counter = 0;
        $tags    = [];
        $unit    = $this->tidy($this->walk($block, $counter, $tags));
        $strip   = $this->stripWrapper($unit);

        if ($strip === null) {
            return ['unit' => $unit, 'tags' => $tags, 'wrap' => null];
        }

        return ['unit' => $strip['key'], 'tags' => $tags, 'wrap' => [$strip['prefix'], $strip['suffix']]];
    }

    /**
     * Restore the wrappers stripped by encode() onto a translated unit, so decode()
     * rebuilds the original inline tags. A no-op for units that were not stripped.
     *
     * @param array{wrap?: ?array{0: string, 1: string}} $encoded
     */
    public function rewrap(string $translatedUnit, array $encoded): string
    {
        if (empty($encoded['wrap'])) {
            return $translatedUnit;
        }

        [$prefix, $suffix] = $encoded['wrap'];

        return $prefix . $translatedUnit . $suffix;
    }

    /**
     * When a unit is nothing but text inside a chain of nested single wrappers
     * (e.g. "{1}{2}Team{/2}{/1}"), peel the wrappers so the translation key and the
     * board display are the bare text ("Team"); render re-applies them via rewrap().
     * A unit with two or more segments ("{1}A{/1} {2}B{/2}") or any inline structure
     * left after peeling is kept whole. Returns null when nothing is strippable.
     *
     * @return array{key: string, prefix: string, suffix: string}|null
     */
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

    /**
     * Collapse the indentation whitespace a theme leaves between tags down to what
     * HTML actually renders (runs → one space, ends trimmed). Case- and
     * entity-preserving, and a strict subset of StringNormalizer's whitespace
     * rules, so the unit hash is unchanged — this only tidies what is stored and
     * shown (e.g. a multi-line "{1}\n  Dashboard {/1}" becomes "{1} Dashboard {/1}").
     */
    private function tidy(string $unit): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $unit));
    }

    /** Shared normalizer — the unit string is normalized before hashing/storing. */
    public function normalizeUnit(string $unit): string
    {
        return StringNormalizer::normalize($unit);
    }

    /**
     * Rebuild an HTML fragment from a translated unit string + the (live) tag map.
     * Text between markers is HTML-escaped; the `{n}` markers expand to real tags.
     *
     * @param array<int, array{open: string, close: string}> $tags
     */
    public function decode(string $translatedUnit, array $tags): string
    {
        // Escape the translated text first (the `{n}` markers are not affected),
        // then expand markers into the real (already-escaped-attr) tags.
        $html = htmlspecialchars($translatedUnit, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ($tags as $n => $tag) {
            $html = str_replace('{' . $n . '/}', $tag['open'], $html);
            $html = str_replace('{' . $n . '}', $tag['open'], $html);
            $html = str_replace('{/' . $n . '}', $tag['close'], $html);
        }

        return $html;
    }

    /**
     * @param array<int, array{open: string, close: string}> $tags
     */
    private function walk(\DOMNode $node, int &$counter, array &$tags): string
    {
        $unit = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $unit .= $child->nodeValue;
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue; // comments, PIs — ignored
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

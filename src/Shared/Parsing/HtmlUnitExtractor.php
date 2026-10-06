<?php

namespace LingoWP\Shared\Parsing;

use Masterminds\HTML5;
use LingoWP\Shared\Text\TranslatableText;

/**
 * The single block-level unit extractor (type-5 parsing spec §2-§5, reused by
 * split fields per the split-field spec §3). One DOM walk finds each leaf
 * block-level element; the codec flattens its inline children into `{n}`
 * placeholders so the whole block is one translation unit.
 *
 * Three callers, one walk:
 *   - units()      → discovery: ordered unit strings for a field's HTML.
 *   - render()     → split-field the_content: replace each unit by its translation.
 *   - walkNode()   → the type-5 page renderer (HtmlRenderer) drives the same walk
 *                    over the page <body>, keying by DOM selector instead of text.
 *
 * Plus one attribute pass (walkAttributes()): alt/title/aria-label/placeholder/
 * button value text becomes its own unit. units()/render() include it; the page
 * renderer calls it after walkNode().
 *
 * Fail-open everywhere: any parse/DOM error leaves the HTML (or the block) as-is.
 * See BLOCK_TAGS/STANDALONE_TEXT_TAGS/isPruned()'s doc comments for the Verse/
 * table-cell/citation-and-summary exceptions this shares with the whole-page
 * type-5 pipeline (one parser, one fix, both callers benefit).
 */
final class HtmlUnitExtractor
{
    /**
     * Whole subtree skipped (never descended). `pre` is unconditional here — the
     * one exception (a Gutenberg Verse block, real prose, not code) is exempted
     * by its own CSS class in isPruned(), never by the bare tag, so a Code or
     * Preformatted block's `<pre>` stays pruned exactly as before.
     */
    private const PRUNE_TAGS = [
        'script' => true, 'style' => true, 'noscript' => true, 'template' => true,
        'textarea' => true, 'iframe' => true, 'svg' => true, 'canvas' => true, 'pre' => true,
    ];

    /**
     * Block-level tags: each leaf is its own unit (a container recurses).
     * `thead`/`tbody`/`tfoot` are only ever valid inside `table` (already a
     * member), so adding them can only affect that one case — without them,
     * hasChildBlock(table) only sees these direct children (never tr/th/td, its
     * grandchildren), wrongly finds no block child, and the whole table +
     * every cell collapses into one leaf. `pre` is likewise only reached here
     * for the one case isPruned() lets through (Verse) — every other `<pre>`
     * is pruned above and never reaches this list at all.
     */
    private const BLOCK_TAGS = [
        'p' => true, 'div' => true, 'li' => true, 'ul' => true, 'ol' => true,
        'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
        'td' => true, 'th' => true, 'tr' => true, 'table' => true, 'thead' => true,
        'tbody' => true, 'tfoot' => true, 'section' => true,
        'article' => true, 'header' => true, 'footer' => true, 'main' => true, 'aside' => true,
        'nav' => true, 'blockquote' => true, 'figure' => true, 'figcaption' => true,
        'dd' => true, 'dt' => true, 'dl' => true, 'form' => true, 'fieldset' => true, 'address' => true,
        'pre' => true,
    ];

    /**
     * Tags that hold standalone supplementary text (a Quote's <cite>, a Details
     * block's <summary>) but aren't themselves in BLOCK_TAGS. Handled by a
     * dedicated branch in walkNode() — NOT by adding them to BLOCK_TAGS — because
     * that would change hasChildBlock() for every existing paragraph too: a
     * `<p>text <cite>x</cite> more text</p>` would suddenly see cite count as a
     * block child, turn the whole <p> into a container instead of a leaf, and
     * silently drop its bare surrounding text nodes (only wrapped elements are
     * ever collected during container recursion). Kept separate, this branch
     * only ever fires for the case it's meant to fix: cite/summary encountered
     * while already recursing through a container (blockquote/details), where a
     * bare text child would otherwise have nowhere to attach.
     */
    private const STANDALONE_TEXT_TAGS = [
        'cite' => true, 'summary' => true,
    ];

    /**
     * Attributes holding text a visitor sees (alt when an image fails, title
     * tooltip, placeholder, a submit button's label) or a screen reader
     * announces (alt, aria-label). Each value is its own unit, keyed by its text
     * hash like any block unit. Tag list null = any element; `value` is further
     * narrowed to button-like inputs and `alt` on an input to type=image in
     * textAttributes() — every other `value` is form data, not text.
     */
    private const TEXT_ATTRIBUTES = [
        'alt'         => ['img' => true, 'area' => true, 'input' => true],
        'title'       => null,
        'aria-label'  => null,
        'placeholder' => ['input' => true, 'textarea' => true],
        'value'       => ['input' => true],
    ];

    /** Pruned tags whose own attributes are never visitor text either. */
    private const NO_ATTRIBUTE_TAGS = [
        'script' => true, 'style' => true, 'noscript' => true, 'template' => true,
    ];

    private TranslationUnitCodec $codec;

    public function __construct(TranslationUnitCodec $codec)
    {
        $this->codec = $codec;
    }

    /**
     * Discovery: the ordered list of block-unit strings in a field's HTML
     * (document order). Inline tags appear as `{n}` markers.
     *
     * @return string[]
     */
    public function units(string $html): array
    {
        $units = [];
        $this->renderFragment($html, static function (string $unit) use (&$units): ?string {
            $units[] = $unit;

            return null; // collect only — no replacement
        });

        return $units;
    }

    /**
     * Split-field render: rebuild a field's HTML, replacing each block unit whose
     * unit string maps to a translation. $translate(unitString) returns the
     * translated unit string (placeholders intact) or null to leave it as-is.
     *
     * @param callable(string):?string $translate
     */
    public function render(string $html, callable $translate): string
    {
        return $this->renderFragment($html, $translate);
    }

    /**
     * render() limited to the attribute pass — for a rendered block whose
     * attributes another filter reads before the_content runs (core/image's
     * lightbox freezes the alt into Interactivity state). Block text is left to
     * the normal the_content pass.
     *
     * @param callable(string):?string $translate
     */
    public function renderAttributes(string $html, callable $translate): string
    {
        if ($html === '' || !class_exists(HTML5::class)) {
            return $html;
        }

        try {
            $html5    = new HTML5();
            $fragment = $html5->loadHTMLFragment($html);
            if (!$fragment instanceof \DOMNode) {
                return $html;
            }

            $this->walkAttributes($fragment, static fn(\DOMElement $element, string $name, string $unit): ?string => $translate($unit));

            return $html5->saveHTML($fragment);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * Walk a node's block-level leaves. For each, encode it and call
     * $onUnit($block, $encoded); a non-null/non-empty return is decoded against
     * the live tag map and replaces the block's inner content. Shared with the
     * type-5 page renderer (which keys $onUnit by DOM selector).
     *
     * @param callable(\DOMElement, array{unit:string, tags:array}):?string $onUnit
     */
    public function walkNode(\DOMNode $node, HTML5 $html5, \DOMDocument $dom, callable $onUnit): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ($this->isPruned($child, $tag)) {
                continue;
            }

            // See STANDALONE_TEXT_TAGS's doc comment: only fires here, mid-recursion —
            // never changes how cite/summary behave as an ordinary inline piece of an
            // already-recognized leaf paragraph (a completely different code path,
            // TranslationUnitCodec::encode()'s own walk(), untouched by this branch).
            if (isset(self::STANDALONE_TEXT_TAGS[$tag]) && !$this->hasChildBlock($child)) {
                $this->handleLeaf($child, $html5, $dom, $onUnit);
                continue;
            }

            if (!isset(self::BLOCK_TAGS[$tag]) || $this->hasChildBlock($child)) {
                $this->walkNode($child, $html5, $dom, $onUnit); // wrapper / container: descend
                continue;
            }

            $this->handleLeaf($child, $html5, $dom, $onUnit);
        }
    }

    /**
     * @param callable(\DOMElement, array{unit:string, tags:array}):?string $onUnit
     */
    private function handleLeaf(\DOMElement $block, HTML5 $html5, \DOMDocument $dom, callable $onUnit): void
    {
        try {
            // Verse is the one leaf whose whitespace is visually significant
            // (white-space: pre-wrap — a poem's line breaks) — every other leaf
            // wants tidy()'s normal collapsing (browsers already collapse
            // whitespace in normal flow, so that's not a real loss anywhere else).
            $preserveWhitespace = strtolower($block->tagName) === 'pre'
                && $this->hasClass($block, 'wp-block-verse');
            $encoded = $this->codec->encode($block, $preserveWhitespace);
            $translated = $onUnit($block, $encoded);
            if ($translated === null || $translated === '') {
                return;
            }

            // Stripped single-wrapper units store the bare text; re-apply the
            // wrappers (if any) so decode rebuilds the original inline tags.
            $decoded  = $this->codec->decode($this->codec->rewrap($translated, $encoded), $encoded['tags']);
            $fragment = $html5->loadHTMLFragment($decoded);
            if (!$fragment instanceof \DOMNode) {
                return;
            }

            $imported = $dom->importNode($fragment, true);
            while ($block->firstChild) {
                $block->removeChild($block->firstChild);
            }
            $block->appendChild($imported);
        } catch (\Throwable $e) {
            // fail-open: leave the block as-is
        }
    }

    /**
     * Parse $html as a fragment (no <html>/<body> wrappers injected), walk it,
     * and serialize back. Used by units()/render(); the page renderer loads a
     * full document and calls walkNode() directly.
     *
     * Block units first, then attribute units (see walkAttributes()) — in that
     * order, because a translated block is rebuilt from its original inline
     * markup, which would put an already-translated inline <img alt> back.
     *
     * @param callable(string):?string $translate unit string => translation or null
     */
    private function renderFragment(string $html, callable $translate): string
    {
        if ($html === '' || !class_exists(HTML5::class)) {
            return $html;
        }

        try {
            $html5    = new HTML5();
            $fragment = $html5->loadHTMLFragment($html);
            $dom      = $fragment instanceof \DOMNode ? $fragment->ownerDocument : null;
            if (!$fragment instanceof \DOMNode || !$dom instanceof \DOMDocument) {
                return $html;
            }

            $this->walkNode($fragment, $html5, $dom, static fn(\DOMElement $block, array $encoded): ?string => $translate($encoded['unit']));
            $this->walkAttributes($fragment, static fn(\DOMElement $element, string $name, string $unit): ?string => $translate($unit));

            return $html5->saveHTML($fragment);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * Attribute units: for every element under $node carrying an allow-listed
     * attribute (TEXT_ATTRIBUTES) with translatable text, call
     * $onAttribute($element, $name, $unit); a non-null/non-empty return
     * replaces the attribute value (setAttribute escapes it — the translation is
     * plain text, never markup).
     *
     * Opted-out subtrees (translate="no", notranslate, …) are skipped whole. A
     * pruned element (iframe, svg, textarea, …) keeps its contents skipped as in
     * walkNode(), but its OWN attributes are still read: an iframe's title or a
     * textarea's placeholder is shown or announced like any other.
     *
     * @param callable(\DOMElement, string, string):?string $onAttribute
     */
    public function walkAttributes(\DOMNode $node, callable $onAttribute): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ($this->isOptedOut($child) || isset(self::NO_ATTRIBUTE_TAGS[$tag])) {
                continue;
            }

            foreach ($this->textAttributes($child, $tag) as $name => $unit) {
                try {
                    $translated = $onAttribute($child, $name, $unit);
                    if ($translated !== null && $translated !== '') {
                        $child->setAttribute($name, $translated);
                    }
                } catch (\Throwable $e) {
                    // fail-open: leave the attribute as-is
                }
            }

            if (!$this->isPruned($child, $tag)) {
                $this->walkAttributes($child, $onAttribute);
            }
        }
    }

    /**
     * The element's allow-listed attributes as unit strings (whitespace tidied
     * like a block unit), machine values (URLs, file names, no letters) left out
     * by the same gate every other source uses.
     *
     * @return array<string, string> attribute name => unit
     */
    private function textAttributes(\DOMElement $element, string $tag): array
    {
        $units = [];
        $type  = strtolower(trim($element->getAttribute('type')));

        foreach (self::TEXT_ATTRIBUTES as $name => $tags) {
            if (($tags !== null && !isset($tags[$tag])) || !$element->hasAttribute($name)) {
                continue;
            }
            // A named submit sends its value with the form ($_POST['action'] ===
            // 'Delete'), so only an unnamed button's value is display-only.
            if ($tag === 'input' && $name === 'value'
                && (!in_array($type, ['submit', 'button', 'reset'], true) || trim($element->getAttribute('name')) !== '')) {
                continue;
            }
            if ($tag === 'input' && $name === 'alt' && $type !== 'image') {
                continue;
            }

            $unit = trim((string) preg_replace('/\s+/u', ' ', $element->getAttribute($name)));
            if (TranslatableText::isTranslatable($unit)) {
                $units[$name] = $unit;
            }
        }

        return $units;
    }

    public function isPruned(\DOMElement $element, string $tag): bool
    {
        // A Verse block's <pre> is real prose (a poem), unlike Code's <pre><code>
        // or Preformatted's bare <pre> — exempted by its own block CSS class,
        // never by the bare tag, so those two keep being pruned unconditionally.
        // Falls through to the ordinary opt-out checks below, so a site can still
        // force-exclude one specific verse block via translate="no" etc.
        if (!($tag === 'pre' && $this->hasClass($element, 'wp-block-verse')) && isset(self::PRUNE_TAGS[$tag])) {
            return true;
        }

        return $this->isOptedOut($element);
    }

    /** Explicit "never translate this subtree" markers, plus the admin bar. */
    private function isOptedOut(\DOMElement $element): bool
    {
        if ($element->hasAttribute('data-lingowp-skip') || $element->hasAttribute('data-lingowp-region')) {
            return true;
        }
        if (strtolower(trim($element->getAttribute('translate'))) === 'no') {
            return true;
        }
        if (strtolower(trim($element->getAttribute('id'))) === 'wpadminbar') {
            return true;
        }

        return $this->hasClass($element, 'notranslate');
    }

    /** Space-separated `class` attribute membership, case-insensitive. */
    private function hasClass(\DOMElement $element, string $class): bool
    {
        $classes = preg_split('/\s+/', strtolower(trim($element->getAttribute('class')))) ?: [];

        return in_array($class, $classes, true);
    }

    private function hasChildBlock(\DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && isset(self::BLOCK_TAGS[strtolower($child->tagName)])) {
                return true;
            }
        }

        return false;
    }
}

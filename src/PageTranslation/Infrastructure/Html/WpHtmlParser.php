<?php

namespace TransLangs\PageTranslation\Infrastructure\Html;

use TransLangs\PageTranslation\Domain\HtmlParser;
use TransLangs\PageTranslation\Domain\SkipRules;
use TransLangs\PageTranslation\Domain\TranslatableSegment;

/**
 * Primary parser driver, built on the WordPress core HTML API
 * (WP_HTML_Processor, available WP 6.4+ and matured in 6.6/6.7).
 *
 * Why this is the default:
 *   - single-pass, streaming: low memory even on large pages
 *   - spec-compliant HTML5: no DOMDocument charset/void-element workarounds
 *   - safe by design: the processor aborts rather than corrupt the document,
 *     so a parse it cannot handle simply yields no segments (we leave the page
 *     untouched) instead of breaking markup
 *
 * Single-parse contract: load() creates the processor; segments() walks it once
 * and drops a bookmark at every translatable spot; render() seeks back to those
 * bookmarks to mutate in place. The document is never parsed twice and the
 * locators are real bookmarks, not re-derived counters.
 */
class WpHtmlParser implements HtmlParser
{
    private string $html = '';

    private ?\WP_HTML_Processor $processor = null;

    /** @var TranslatableSegment[] */
    private array $segments = [];

    /** @var TranslatableSegment[] link hrefs collected during the same scan */
    private array $links = [];

    /**
     * Replacement plan captured during segments(), keyed by bookmark/locator.
     * For text:      ['kind' => 'text']
     * For attribute: ['kind' => 'attr', 'attr' => 'alt']
     *
     * @var array<string, array{kind: string, attr?: string}>
     */
    private array $plan = [];

    private bool $scanned = false;

    public function __construct(private SkipRules $rules)
    {
    }

    public function name(): string
    {
        return 'wp-html-api';
    }

    public function isAvailable(): bool
    {
        return class_exists('WP_HTML_Processor');
    }

    public function load(string $html): bool
    {
        if (!$this->isAvailable() || trim($html) === '') {
            return false;
        }

        $processor = \WP_HTML_Processor::create_full_parser($html);

        if ($processor === null) {
            return false;
        }

        $this->html      = $html;
        $this->processor = $processor;
        $this->segments  = [];
        $this->links     = [];
        $this->plan      = [];
        $this->scanned   = false;

        return true;
    }

    /**
     * @return TranslatableSegment[]
     */
    public function segments(): array
    {
        if ($this->processor === null) {
            return [];
        }

        if ($this->scanned) {
            return $this->segments;
        }

        $p       = $this->processor;
        $counter = 0;

        while ($p->next_token()) {
            $type = $p->get_token_type();

            if ($type === '#tag') {
                $this->captureAttributes($p, $counter);
                $this->captureLink($p, $counter);
                continue;
            }

            if ($type !== '#text') {
                continue;
            }

            $text = $p->get_modifiable_text();

            if (!$this->rules->isTranslatableText($text) || $this->insideSkippedSubtree($p)) {
                continue;
            }

            $locator = 'tl' . (++$counter);

            // The bookmark IS the locator: render() will seek() back to it.
            if (!$p->set_bookmark($locator)) {
                continue; // bookmark budget exhausted; skip this one safely
            }

            $this->segments[]     = TranslatableSegment::text($text, $this->currentContext($p), $locator);
            $this->plan[$locator] = ['kind' => 'text'];
        }

        $this->scanned = true;

        return $this->segments;
    }

    /**
     * @return TranslatableSegment[]
     */
    public function links(): array
    {
        if (!$this->scanned) {
            $this->segments();
        }

        return $this->links;
    }

    /**
     * @param array<string, string> $replacements
     */
    public function render(array $replacements): string
    {
        if ($this->processor === null) {
            return $this->html;
        }

        // Guarantee the scan ran so bookmarks/plan exist.
        if (!$this->scanned) {
            $this->segments();
        }

        if ($replacements === []) {
            return $this->processor->get_updated_html();
        }

        $p = $this->processor;

        foreach ($replacements as $locator => $value) {
            if (!isset($this->plan[$locator])) {
                continue;
            }

            // Seek back to the exact spot this locator bookmarked.
            if (!$p->seek($locator)) {
                continue;
            }

            $entry = $this->plan[$locator];

            if ($entry['kind'] === 'text') {
                $p->set_modifiable_text($value);
            } elseif ($entry['kind'] === 'attr' && isset($entry['attr'])) {
                $p->set_attribute($entry['attr'], $value);
            }
        }

        return $p->get_updated_html();
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    private function insideSkippedSubtree(\WP_HTML_Processor $p): bool
    {
        foreach ($p->get_breadcrumbs() as $tag) {
            if ($this->rules->isSkippedTag($tag)) {
                return true;
            }
        }

        return false;
    }

    private function currentContext(\WP_HTML_Processor $p): string
    {
        $crumbs = $p->get_breadcrumbs();
        $tail   = array_slice($crumbs, -2);

        return strtolower(implode('>', $tail));
    }

    /**
     * Capture translatable attributes on a tag token, bookmarking each so
     * render() can seek back. Counter is shared with text to keep locators
     * unique and document-ordered.
     */
    private function captureAttributes(\WP_HTML_Processor $p, int &$counter): void
    {
        $tag = $p->get_tag();

        if ($tag === null || $this->rules->isSkippedTag($tag) || $this->elementOptedOut($p)) {
            return;
        }

        $tagLower = strtolower($tag);

        if ($tagLower === 'meta') {
            $name     = $p->get_attribute('name');
            $property = $p->get_attribute('property');

            if (!$this->rules->isTranslatableMeta(
                is_string($name) ? $name : null,
                is_string($property) ? $property : null
            )) {
                return;
            }
        }

        foreach ($this->rules->translatableAttributes($tag) as $attr) {
            $value = $p->get_attribute($attr);

            if (!is_string($value) || !$this->rules->isTranslatableText($value)) {
                continue;
            }

            $locator = 'tl' . (++$counter);

            if (!$p->set_bookmark($locator)) {
                continue;
            }

            $context = $this->attributeContext($p, $tagLower, $attr);

            $this->segments[]     = TranslatableSegment::attribute($value, $context, $locator);
            $this->plan[$locator] = ['kind' => 'attr', 'attr' => $attr];
        }
    }

    /**
     * Capture an <a href> as a link segment so the LinkRewriter can prefix it.
     * Skipped subtrees and opted-out elements are ignored, matching text rules.
     */
    private function captureLink(\WP_HTML_Processor $p, int &$counter): void
    {
        if (strtolower((string) $p->get_tag()) !== 'a') {
            return;
        }

        if ($this->insideSkippedSubtree($p) || $this->elementOptedOut($p)) {
            return;
        }

        // Language-switcher links manage their own per-language URLs. Rewriting
        // them with the active language would clobber the "switch to X" target
        // (e.g. turning the back-to-English /about link into /fr/about).
        if ($p->get_attribute('data-tls-lang') !== null) {
            return;
        }

        $href = $p->get_attribute('href');

        if (!is_string($href) || $href === '') {
            return;
        }

        $locator = 'tl' . (++$counter);

        if (!$p->set_bookmark($locator)) {
            return;
        }

        $this->links[]        = TranslatableSegment::attribute($href, 'a@href', $locator);
        $this->plan[$locator] = ['kind' => 'attr', 'attr' => 'href'];
    }

    private function attributeContext(\WP_HTML_Processor $p, string $tagLower, string $attr): string
    {
        return $tagLower . '@' . $attr;
    }

    private function elementOptedOut(\WP_HTML_Processor $p): bool
    {
        if ($p->get_attribute('data-no-translate') !== null) {
            return true;
        }

        $class = $p->get_attribute('class');
        if (is_string($class) && $this->rules->isSkippedByClass(preg_split('/\s+/', trim($class)) ?: [])) {
            return true;
        }

        $id = $p->get_attribute('id');

        return $this->rules->isSkippedById(is_string($id) ? $id : null);
    }
}

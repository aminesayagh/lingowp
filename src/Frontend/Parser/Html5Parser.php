<?php

namespace TransLangs\Frontend\Parser;

/**
 * Fallback parser driver using the Masterminds/html5-php library, which parses
 * into a standard DOMDocument and lets us traverse with XPath.
 *
 * Used only when the WordPress core HTML API is unavailable (very old WP) or
 * when a future feature needs full-tree mutation the streaming processor can't
 * do. It is an optional Composer dependency; if the class is missing, the
 * factory will not select this driver.
 *
 * Locators here are DOMNode spl_object_id values, resolved within a single
 * load() lifetime (segments() and render() share the same parsed tree).
 */
class Html5Parser implements ParserInterface
{
    private ?\DOMDocument $dom = null;
    private string $html = '';

    /** @var array<int, \DOMNode> locator => node */
    private array $nodes = [];

    public function __construct(private SkipRules $rules)
    {
    }

    public function name(): string
    {
        return 'masterminds-html5';
    }

    public function isAvailable(): bool
    {
        return class_exists(\Masterminds\HTML5::class);
    }

    public function load(string $html): bool
    {
        if (!$this->isAvailable() || trim($html) === '') {
            return false;
        }

        $this->html  = $html;
        $this->nodes = [];

        try {
            $html5     = new \Masterminds\HTML5(['disable_html_ns' => true]);
            $this->dom = $html5->loadHTML($html);
        } catch (\Throwable $e) {
            $this->dom = null;
            return false;
        }

        return $this->dom !== null;
    }

    /**
     * @return Segment[]
     */
    public function segments(): array
    {
        if ($this->dom === null) {
            return [];
        }

        $segments = [];
        $xpath    = new \DOMXPath($this->dom);

        // Text nodes that are not inside a skipped subtree.
        foreach ($xpath->query('//text()') as $node) {
            if (!$this->rules->isTranslatableText($node->nodeValue ?? '')) {
                continue;
            }

            if ($this->ancestorSkipped($node)) {
                continue;
            }

            $id = spl_object_id($node);
            $this->nodes[$id] = $node;

            $segments[] = Segment::text(
                $node->nodeValue ?? '',
                strtolower($node->parentNode->nodeName ?? ''),
                (string) $id
            );
        }

        // Translatable attributes.
        foreach ($xpath->query('//*') as $el) {
            if (!$el instanceof \DOMElement || $this->elementSkipped($el)) {
                continue;
            }

            $tag = strtolower($el->nodeName);

            if ($tag === 'meta' && !$this->rules->isTranslatableMeta(
                $el->getAttribute('name') ?: null,
                $el->getAttribute('property') ?: null
            )) {
                continue;
            }

            foreach ($this->rules->translatableAttributes($tag) as $attr) {
                if (!$el->hasAttribute($attr)) {
                    continue;
                }

                $value = $el->getAttribute($attr);
                if (!$this->rules->isTranslatableText($value)) {
                    continue;
                }

                $attrNode = $el->getAttributeNode($attr);
                $id       = spl_object_id($attrNode);
                $this->nodes[$id] = $attrNode;

                $segments[] = Segment::attribute($value, $tag . '@' . $attr, (string) $id);
            }
        }

        return $segments;
    }

    /**
     * @return Segment[]
     */
    public function links(): array
    {
        if ($this->dom === null) {
            return [];
        }

        $links = [];
        $xpath = new \DOMXPath($this->dom);

        foreach ($xpath->query('//a[@href]') as $el) {
            if (!$el instanceof \DOMElement || $this->ancestorSkipped($el) || $this->elementSkipped($el)) {
                continue;
            }

            $href = $el->getAttribute('href');
            if ($href === '') {
                continue;
            }

            $attrNode = $el->getAttributeNode('href');
            $id       = spl_object_id($attrNode);
            $this->nodes[$id] = $attrNode;

            $links[] = Segment::attribute($href, 'a@href', (string) $id);
        }

        return $links;
    }

    /**
     * @param array<string, string> $replacements
     */
    public function render(array $replacements): string
    {
        if ($this->dom === null) {
            return $this->html;
        }

        foreach ($replacements as $locator => $value) {
            $node = $this->nodes[(int) $locator] ?? null;

            if ($node instanceof \DOMText || $node instanceof \DOMAttr) {
                $node->nodeValue = $value;
            }
        }

        try {
            $html5 = new \Masterminds\HTML5(['disable_html_ns' => true]);
            return $html5->saveHTML($this->dom);
        } catch (\Throwable $e) {
            return $this->html;
        }
    }

    private function ancestorSkipped(\DOMNode $node): bool
    {
        for ($n = $node->parentNode; $n !== null; $n = $n->parentNode) {
            if ($n instanceof \DOMElement && $this->elementSkipped($n)) {
                return true;
            }
        }

        return false;
    }

    private function elementSkipped(\DOMElement $el): bool
    {
        if ($this->rules->isSkippedTag($el->nodeName)) {
            return true;
        }

        if ($el->hasAttribute('data-no-translate')) {
            return true;
        }

        if ($el->hasAttribute('class')) {
            $classes = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];
            if ($this->rules->isSkippedByClass($classes)) {
                return true;
            }
        }

        return $this->rules->isSkippedById($el->getAttribute('id') ?: null);
    }
}

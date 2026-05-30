<?php

namespace TransLangs\PageTranslation\Domain;

/**
 * Contract for an HTML parser driver.
 *
 * Lifecycle (single-parse guarantee):
 *   1. load($html)          parse ONCE, keep the loaded state internally
 *   2. segments()           read translatable segments from that state
 *   3. render($replacements) mutate that same state in place, serialize
 *
 * Implementations MUST parse the document at most once per load() and reuse
 * that parsed state for both segments() and render(). They must NOT re-parse
 * inside render(), because the locators handed out by segments() are only
 * meaningful against the exact parse that produced them. Downstream code
 * (extractor, renderer) depends only on this interface, never on a concrete
 * parser.
 */
interface HtmlParser
{
    /**
     * Load and parse an HTML document once. Returns false if the input cannot
     * be handled, in which case the caller should leave the HTML untouched.
     */
    public function load(string $html): bool;

    /**
     * Return every translatable segment from the already-parsed document, in
     * document order, skipping the configured non-translatable subtrees.
     *
     * @return TranslatableSegment[]
     */
    public function segments(): array;

    /**
     * Return the navigable link hrefs found in the document as attribute
     * segments (one per <a href>), using the same locator scheme as
     * segments() so render() can replace them by locator. Collected during the
     * same single parse; calling this does not re-parse.
     *
     * @return TranslatableSegment[]
     */
    public function links(): array;

    /**
     * Apply replacements keyed by TranslatableSegment::$locator against the SAME parsed
     * state, then serialize. Locators with no entry in the map are left as-is.
     *
     * @param array<string, string> $replacements locator => translated value
     */
    public function render(array $replacements): string;

    /**
     * Human-readable driver name, for diagnostics/logs.
     */
    public function name(): string;
}

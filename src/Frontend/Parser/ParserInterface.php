<?php

namespace TransLangs\Frontend\Parser;

/**
 * Contract for an HTML parser driver.
 *
 * Implementations load a full HTML document, walk it while honoring the skip
 * rules, expose the translatable segments, and can write replacement values
 * back, returning the serialized HTML. Downstream code (extractor, renderer)
 * depends only on this interface, never on a concrete parser.
 */
interface ParserInterface
{
    /**
     * Load an HTML document for processing. Returns false if the input cannot
     * be handled, in which case the caller should leave the HTML untouched.
     */
    public function load(string $html): bool;

    /**
     * Walk the loaded document and return every translatable segment, in
     * document order, skipping the configured non-translatable subtrees.
     *
     * @return Segment[]
     */
    public function segments(): array;

    /**
     * Apply replacements keyed by Segment::$locator, then serialize.
     * Locators with no entry in the map are left as-is.
     *
     * @param array<string, string> $replacements locator => translated value
     */
    public function render(array $replacements): string;

    /**
     * Human-readable driver name, for diagnostics/logs.
     */
    public function name(): string;
}
<?php

namespace TransLangs\PublicSite\Infrastructure;

final class WpHtmlProcessorRenderParser implements HtmlRenderParser
{
    public function __construct(private HtmlTextTools $textTools) {}

    public function isSupported(): bool
    {
        if (!class_exists('WP_HTML_Processor')) {
            return false;
        }

        foreach ([
            'create_full_parser',
            'next_token',
            'get_token_name',
            'get_modifiable_text',
            'set_modifiable_text',
            'get_breadcrumbs',
            'get_updated_html',
        ] as $method) {
            if (!method_exists('WP_HTML_Processor', $method)) {
                return false;
            }
        }

        return true;
    }

    public function translate(string $html, array $translationsByHash, array $ownedHashes): RenderedHtmlResult
    {
        if (!$this->isSupported()) {
            return RenderedHtmlResult::failure($html);
        }

        try {
            $processor = \WP_HTML_Processor::create_full_parser($html);
            if (!$processor instanceof \WP_HTML_Processor) {
                return RenderedHtmlResult::failure($html);
            }

            $replacementCount = 0;
            $visitedTextNodes = false;
            $candidates       = [];

            while ($processor->next_token()) {
                if ($processor->get_token_name() !== '#text') {
                    continue;
                }

                $breadcrumbs = array_map('strtolower', $processor->get_breadcrumbs());
                if ($this->textTools->shouldSkipPath($breadcrumbs)) {
                    continue;
                }

                $text       = $processor->get_modifiable_text();
                $normalized = $this->textTools->normalizedText($text);
                if ($normalized === '') {
                    continue;
                }

                $visitedTextNodes = true;
                $hashHex = $this->textTools->originalHashHex($normalized);
                if (isset($translationsByHash[$hashHex])) {
                    $updated = $this->textTools->replacePreservingEdgeWhitespace(
                        $text,
                        $translationsByHash[$hashHex]
                    );

                    if (!$processor->set_modifiable_text($updated)) {
                        return RenderedHtmlResult::failure($html);
                    }

                    ++$replacementCount;
                    continue;
                }

                if (!isset($ownedHashes[$hashHex])) {
                    $selector = $this->textTools->structuralSelector($breadcrumbs);
                    $candidates[$hashHex . '|' . $selector] = [
                        'original_text'      => $text,
                        'normalized_text'    => $normalized,
                        'original_hash_hex'  => $hashHex,
                        'selector'           => $selector,
                    ];
                }
            }

            if (!$visitedTextNodes) {
                return RenderedHtmlResult::failure($html);
            }

            return RenderedHtmlResult::success(
                $processor->get_updated_html(),
                $replacementCount,
                array_values($candidates)
            );
        } catch (\Throwable) {
            return RenderedHtmlResult::failure($html);
        }
    }
}

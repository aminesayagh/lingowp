<?php

namespace TransLangs\PublicSite\Infrastructure;

final class HtmlRenderParserFactory
{
    public function __construct(private HtmlTextTools $textTools) {}

    /**
     * @param array<string, string> $translationsByHash
     * @param array<string, true>   $ownedHashes
     */
    public function translate(string $html, array $translationsByHash, array $ownedHashes): RenderedHtmlResult
    {
        $wpParser = new WpHtmlProcessorRenderParser($this->textTools);
        if ($wpParser->isSupported()) {
            $result = $wpParser->translate($html, $translationsByHash, $ownedHashes);
            if ($result->success) {
                return $result;
            }
        }

        return (new MastermindsHtml5RenderParser($this->textTools))->translate(
            $html,
            $translationsByHash,
            $ownedHashes
        );
    }
}

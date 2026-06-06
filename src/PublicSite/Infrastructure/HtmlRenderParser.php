<?php

namespace TransLangs\PublicSite\Infrastructure;

interface HtmlRenderParser
{
    /**
     * @param array<string, string> $translationsByHash original_hash_hex => translated_text
     * @param array<string, true>   $ownedHashes        original_hash_hex => true
     */
    public function translate(string $html, array $translationsByHash, array $ownedHashes): RenderedHtmlResult;
}

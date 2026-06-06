<?php

namespace TransLangs\PublicSite\Infrastructure;

final class RenderedHtmlResult
{
    /**
     * @param array<int, array{original_text: string, normalized_text: string, original_hash_hex: string, selector: string}> $fallbackCandidates
     */
    private function __construct(
        public readonly bool $success,
        public readonly string $html,
        public readonly int $replacementCount,
        public readonly array $fallbackCandidates
    ) {}

    /**
     * @param array<int, array{original_text: string, normalized_text: string, original_hash_hex: string, selector: string}> $fallbackCandidates
     */
    public static function success(string $html, int $replacementCount, array $fallbackCandidates): self
    {
        return new self(true, $html, $replacementCount, $fallbackCandidates);
    }

    public static function failure(string $html): self
    {
        return new self(false, $html, 0, []);
    }
}

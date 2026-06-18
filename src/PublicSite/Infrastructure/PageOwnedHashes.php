<?php

namespace TransLangs\PublicSite\Infrastructure;

/**
 * Tier-1 ownership gate (v5 §5): the set of original hashes this request already
 * resolved through a structured source (post/term content). It is populated by
 * the structured render resolver as it processes the page and read by gettext
 * capture, so a string that also appears as structured content is not re-captured
 * as a static-text source. Zero queries — pure request-local memory.
 *
 * Best-effort by nature (a gettext call before its owning content renders is not
 * yet gated here); the worker's Tier-2 OwnedStringIndex is the authoritative
 * cross-table check.
 */
final class PageOwnedHashes
{
    /** @var array<string, true> hex(original_hash) => true */
    private array $owned = [];

    public function mark(string $rawHash): void
    {
        $this->owned[bin2hex($rawHash)] = true;
    }

    public function owns(string $rawHash): bool
    {
        return isset($this->owned[bin2hex($rawHash)]);
    }
}

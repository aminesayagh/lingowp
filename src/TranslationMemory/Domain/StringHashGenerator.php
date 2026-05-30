<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * Generates stable lookup hashes for normalized strings.
 *
 * The same normalized string always produces the same hash, which lets the
 * repository do fast indexed lookups instead of comparing long text.
 */
class StringHashGenerator
{
    public function generate(string $normalizedText): string
    {
        return hash('sha256', $normalizedText);
    }
}

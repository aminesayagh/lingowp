<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * Makes strings stable before hashing and storage.
 *
 * "About us", " About us ", and "About   us" must all normalize to "About us".
 */
class StringNormalizer
{
    public function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);

        return $text ?? '';
    }
}

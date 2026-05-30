<?php

namespace TransLangs\TranslationMemory\Domain;

final class LanguagePair
{
    public function __construct(
        public readonly string $sourceLang,
        public readonly string $targetLang
    ) {
    }
}

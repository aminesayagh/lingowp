<?php

namespace TransLangs\TranslationMemory\Domain;

final class TranslationEntry
{
    public function __construct(
        public readonly int $id,
        public readonly string $sourceLang,
        public readonly string $targetLang,
        public readonly string $originalHash,
        public readonly string $originalText,
        public readonly ?string $translatedText,
        public readonly int $status
    ) {
    }
}

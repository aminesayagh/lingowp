<?php

namespace TransLangs\TranslationMemory\Domain;

final class TranslationProviderType
{
    public const MANUAL = 'manual';
    public const DEEPL = 'deepl';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::MANUAL,
            self::DEEPL,
        ];
    }
}

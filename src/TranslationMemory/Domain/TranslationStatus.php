<?php

namespace TransLangs\TranslationMemory\Domain;

final class TranslationStatus
{
    public const DISCOVERED = 0;
    public const MACHINE = 1;
    public const REVIEWED = 2;
    public const IGNORED = 3;
    public const NEEDS_REVIEW = 4;

    /**
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return [
            self::DISCOVERED   => 'Discovered',
            self::MACHINE      => 'Machine',
            self::REVIEWED     => 'Reviewed',
            self::IGNORED      => 'Ignored',
            self::NEEDS_REVIEW => 'Needs review',
        ];
    }
}

<?php

namespace LingoWP\Shared\Text;

final class IdentityKey
{
    private function __construct() {}

    public static function of(array $cols): string
    {
        $parts = [];
        foreach ($cols as $column => $value) {
            $parts[] = $column . '=' . bin2hex((string) $value);
        }

        return implode('|', $parts);
    }
}

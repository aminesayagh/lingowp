<?php

namespace TransLangs\BackOffice\Infrastructure\Rest;

/**
 * source_key travels over the wire as lowercase hex (64 chars). This is the one
 * place the API converts hex↔binary; clients never compute or send raw binary.
 */
final class HexSourceKey
{
    private function __construct() {}

    public static function isValid(string $hex): bool
    {
        return preg_match('/^[0-9a-fA-F]{64}$/', $hex) === 1;
    }

    /** Raw 32-byte key, or null if the hex is malformed. */
    public static function toBinary(string $hex): ?string
    {
        if (!self::isValid($hex)) {
            return null;
        }

        $bin = hex2bin(strtolower($hex));

        return $bin === false ? null : $bin;
    }
}

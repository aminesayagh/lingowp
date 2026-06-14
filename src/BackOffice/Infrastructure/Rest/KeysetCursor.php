<?php

namespace TransLangs\BackOffice\Infrastructure\Rest;

/**
 * Opaque base64url cursors for keyset pagination (never OFFSET). Item lists page
 * by (owner_id, id); the ignored list pages by id.
 */
final class KeysetCursor
{
    private function __construct() {}

    /** @param array{owner:int,id:int}|null $cursor */
    public static function encodeOwner(?array $cursor): ?string
    {
        if ($cursor === null) {
            return null;
        }

        return self::b64(((int) $cursor['owner']) . ':' . ((int) $cursor['id']));
    }

    /** @return array{owner:int,id:int}|null */
    public static function decodeOwner(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $parts = explode(':', self::unb64($cursor));
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return null;
        }

        return ['owner' => (int) $parts[0], 'id' => (int) $parts[1]];
    }

    public static function encodeId(?int $id): ?string
    {
        return $id === null ? null : self::b64((string) $id);
    }

    public static function decodeId(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $value = self::unb64($cursor);

        return ctype_digit($value) ? (int) $value : null;
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function unb64(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}

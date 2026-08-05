<?php

namespace TransLangs\Shared\Text;

/**
 * Deterministic in-memory digest of an identity-column map, used to index
 * batched render results and look them back up. Both the repository (when it
 * builds the result map) and the render resolvers (when they look a row up) call
 * this with the same columns in the same order, so the strings line up.
 *
 * Not stored anywhere — the database's authoritative identity remains the
 * readable parent columns; this is only a request-local array key.
 */
final class IdentityKey
{
    private function __construct() {}

    /**
     * @param array<string, scalar> $cols column => value (binary hashes allowed)
     */
    public static function of(array $cols): string
    {
        // Encode every value the same way (hex of its string form), so an int 5
        // and the string "5" MySQL returns produce the same key, and binary hashes
        // stay UTF-8 safe. Type must never affect the key, or render lookups built
        // from PHP ints miss rows the repository keyed from DB strings.
        $parts = [];
        foreach ($cols as $column => $value) {
            $parts[] = $column . '=' . bin2hex((string) $value);
        }

        return implode('|', $parts);
    }
}

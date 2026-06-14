<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * Status vocabulary (architecture §4).
 *
 * Translation rows: machine (default on insert) / reviewed / needs_review.
 * Source rows: discovered (translatable) / ignored (never translate).
 * "Original text changed" is NOT a stored status — it is derived at read time
 * from translated_against_hash <> current_hash.
 */
final class TranslationStatus
{
    public const MACHINE      = 1;
    public const REVIEWED     = 2;
    public const NEEDS_REVIEW = 4;

    public const SOURCE_DISCOVERED = 0;
    public const SOURCE_IGNORED    = 3;

    private function __construct() {}
}

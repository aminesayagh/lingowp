<?php

namespace LingoWP\Shared\Source;

final class TranslationStatus
{
    public const MACHINE      = 1;
    public const REVIEWED     = 2;
    public const NEEDS_REVIEW = -1;

    public const SOURCE_DISCOVERED = 0;
    public const SOURCE_IGNORED    = -1;
    public const SOURCE_STALE      = 2;

    private function __construct() {}
}

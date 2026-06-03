<?php

namespace TransLangs\ContentSource\Application;

/**
 * Summary returned by ScanRenderedArchetypes::scan().
 */
final class ArchetypeScanResult
{
    /**
     * @param int                                                                $scanned    Total URLs attempted.
     * @param int                                                                $discovered Total new rows inserted into the translation memory.
     * @param array<int, array{url: string, archetype: string, error: string}>  $errors     Per-URL failures (fetch / parse errors).
     */
    public function __construct(
        public readonly int $scanned,
        public readonly int $discovered,
        public readonly array $errors
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}

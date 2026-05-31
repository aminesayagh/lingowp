<?php

namespace TransLangs\ContentSource\Application;

/**
 * Summary returned by ScanStructuredSources::scan().
 */
final class ScanResult
{
    /**
     * @param int                                               $totalStrings  Raw strings read across all readers.
     * @param int                                               $totalInserted Rows newly inserted into the translation memory.
     * @param array<string, array{strings: int, inserted: int}> $byReader      Per-reader breakdown keyed by SourceReader::key().
     */
    public function __construct(
        public readonly int $totalStrings,
        public readonly int $totalInserted,
        public readonly array $byReader
    ) {
    }
}

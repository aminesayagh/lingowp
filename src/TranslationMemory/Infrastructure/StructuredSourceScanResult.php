<?php

namespace TransLangs\TranslationMemory\Infrastructure;

final class StructuredSourceScanResult
{
    /**
     * @param array<string, int> $sourceByRoute
     * @param array<string, int> $insertedByRoute
     */
    public function __construct(
        public readonly array $sourceByRoute,
        public readonly array $insertedByRoute,
        public readonly int $targetLanguageCount,
        public readonly int $skipped
    ) {}

    public function totalSourceStrings(): int
    {
        return array_sum($this->sourceByRoute);
    }

    public function totalInsertedRows(): int
    {
        return array_sum($this->insertedByRoute);
    }

    public function totalExistingRows(): int
    {
        return max(0, ($this->totalSourceStrings() * $this->targetLanguageCount) - $this->totalInsertedRows());
    }
}

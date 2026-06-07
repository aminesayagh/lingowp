<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\TranslationMemory\Domain\RowRef;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;

class QueryTranslations
{
    public function __construct(private TranslationMemoryRepository $repository) {}

    /**
     * @param array{
     *   target_lang?: string, status?: int|null, statuses?: int[],
     *   search_original?: string, search_translated?: string,
     *   route_table?: string
     * } $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function query(array $filters, int $page, int $perPage): array
    {
        return $this->repository->query($filters, $page, $perPage);
    }

    /**
     * @param array{target_lang?: string, search_original?: string, search_translated?: string} $filters
     * @return array<string, int> source-type slug => row count
     */
    public function sourceTypeCounts(array $filters): array
    {
        return $this->repository->sourceTypeCounts($filters);
    }

    /**
     * @return list<array{row_ref: string, target_lang: string, original_text: string, translated_text: string|null, status: int}>
     */
    public function languageSiblings(RowRef $rowRef): array
    {
        return $this->repository->languageSiblings($rowRef);
    }
}

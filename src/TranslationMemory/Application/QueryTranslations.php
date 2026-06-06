<?php

namespace TransLangs\TranslationMemory\Application;

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
}

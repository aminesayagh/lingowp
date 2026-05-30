<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;

class QueryTranslations
{
    public function __construct(private TranslationMemoryRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function query(array $filters, int $page, int $perPage): array
    {
        return $this->repository->query($filters, $page, $perPage);
    }

    /**
     * @return array<int, array{source_lang: string, target_lang: string}>
     */
    public function languagePairs(): array
    {
        return $this->repository->languagePairs();
    }
}

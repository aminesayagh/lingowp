<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationStatus;

class ReviewTranslation
{
    public function __construct(private TranslationMemoryRepository $repository)
    {
    }

    public function review(int $id): bool
    {
        $row = $this->repository->find($id);

        if ($row === null || (int) ($row['status'] ?? -1) !== TranslationStatus::NEEDS_REVIEW) {
            return false;
        }

        $this->repository->markReviewed($id);

        return true;
    }
}

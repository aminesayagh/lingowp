<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\TranslationMemory\Domain\RowRef;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationStatus;

class ReviewTranslation
{
    public function __construct(private TranslationMemoryRepository $repository) {}

    public function review(RowRef $rowRef): bool
    {
        $row = $this->repository->find($rowRef);

        if ($row === null || (int) ($row['status'] ?? -1) !== TranslationStatus::NEEDS_REVIEW) {
            return false;
        }

        $this->repository->markReviewed($rowRef);

        return true;
    }
}

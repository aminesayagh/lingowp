<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;

class ReviewTranslation
{
    public function __construct(private TranslationMemoryRepository $repository)
    {
    }

    public function review(int $id): void
    {
        $this->repository->markReviewed($id);
    }
}

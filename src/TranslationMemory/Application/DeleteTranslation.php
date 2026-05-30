<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;

class DeleteTranslation
{
    public function __construct(private TranslationMemoryRepository $repository)
    {
    }

    public function delete(int $id): void
    {
        $this->repository->delete($id);
    }
}

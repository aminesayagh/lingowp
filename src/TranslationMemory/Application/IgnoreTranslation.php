<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;

class IgnoreTranslation
{
    public function __construct(private TranslationMemoryRepository $repository)
    {
    }

    public function ignore(int $id): void
    {
        $this->repository->ignoreString($id);
    }
}

<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\TranslationMemory\Domain\RowRef;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;

class IgnoreTranslation
{
    public function __construct(private TranslationMemoryRepository $repository) {}

    public function ignore(RowRef $rowRef): void
    {
        $this->repository->ignoreString($rowRef);
    }
}

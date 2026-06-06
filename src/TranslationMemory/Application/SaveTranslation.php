<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\TranslationMemory\Domain\RowRef;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationStatus;

class SaveTranslation
{
    public function __construct(private TranslationMemoryRepository $repository) {}

    public function save(RowRef $rowRef, string $translatedText): void
    {
        $status = $translatedText === ''
            ? TranslationStatus::DISCOVERED
            : TranslationStatus::REVIEWED;

        $this->repository->saveTranslation($rowRef, $translatedText, $status);
    }
}

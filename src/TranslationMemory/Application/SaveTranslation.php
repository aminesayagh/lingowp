<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationStatus;

class SaveTranslation
{
    public function __construct(private TranslationMemoryRepository $repository)
    {
    }

    public function save(int $id, string $translatedText): void
    {
        $status = $translatedText === ''
            ? TranslationStatus::DISCOVERED
            : TranslationStatus::REVIEWED;

        $this->repository->saveTranslation($id, $translatedText, $status);
    }
}

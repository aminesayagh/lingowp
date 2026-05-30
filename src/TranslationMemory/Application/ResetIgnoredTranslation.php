<?php

namespace TransLangs\TranslationMemory\Application;

use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationStatus;

class ResetIgnoredTranslation
{
    public function __construct(private TranslationMemoryRepository $repository)
    {
    }

    public function reset(int $id): bool
    {
        $row = $this->repository->find($id);

        if ($row === null || (int) ($row['status'] ?? -1) !== TranslationStatus::IGNORED) {
            return false;
        }

        $this->repository->markDiscovered($id);

        return true;
    }
}

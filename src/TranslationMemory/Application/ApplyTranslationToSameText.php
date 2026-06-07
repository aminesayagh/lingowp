<?php

namespace TransLangs\TranslationMemory\Application;

use InvalidArgumentException;
use TransLangs\TranslationMemory\Domain\RowRef;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationStatus;

/**
 * Propagate one row's translation to every still-untranslated row that shares
 * the same original text in the same target language (e.g. "Add to cart"
 * appearing in several places). Existing translations are never overwritten.
 */
class ApplyTranslationToSameText
{
    public function __construct(private TranslationMemoryRepository $repository) {}

    /**
     * @return int Number of rows updated.
     */
    public function apply(RowRef $source): int
    {
        $row = $this->repository->find($source);
        if ($row === null) {
            return 0;
        }

        $text = (string) ($row['translated_text'] ?? '');
        if (trim($text) === '') {
            return 0;
        }

        $hashHex    = (string) ($row['original_hash'] ?? '');
        $targetLang = (string) ($row['target_lang'] ?? '');
        if ($hashHex === '' || $targetLang === '') {
            return 0;
        }

        $count = 0;
        foreach ($this->repository->sameOriginalUntranslated($hashHex, $targetLang) as $ref) {
            if ($ref === $source->toString()) {
                continue;
            }

            try {
                $target = RowRef::parse($ref);
            } catch (InvalidArgumentException) {
                continue;
            }

            $this->repository->saveTranslation($target, $text, TranslationStatus::REVIEWED);
            ++$count;
        }

        return $count;
    }
}

<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * Identity of a single translation-memory source string.
 */
final class TranslationSourceIdentity
{
    public function __construct(
        public readonly int $sourceId,
        public readonly string $sourceType,
        public readonly string $context,
        public readonly string $originalText,
        public readonly string $originalHash,
        public readonly string $normalizedText
    ) {
    }

    public static function source(
        int $sourceId,
        string $sourceType,
        string $context,
        string $originalText,
        string $originalHash,
        string $normalizedText
    ): self {
        return new self($sourceId, $sourceType, $context, $originalText, $originalHash, $normalizedText);
    }

    public static function entity(
        int $sourceId,
        string $sourceType,
        string $context,
        string $originalText,
        string $originalHash,
        string $normalizedText
    ): self {
        return self::source($sourceId, $sourceType, $context, $originalText, $originalHash, $normalizedText);
    }

    /**
     * @return array{
     *   source_id: int,
     *   source_type: string,
     *   original_hash: string,
     *   original_text: string,
     *   normalized_text: string,
     *   context: string
     * }
     */
    public function toDatabaseRow(): array
    {
        return [
            'source_id'       => $this->sourceId,
            'source_type'     => $this->sourceType,
            'original_hash'   => $this->originalHash,
            'original_text'   => $this->originalText,
            'normalized_text' => $this->normalizedText,
            'context'         => $this->context,
        ];
    }
}

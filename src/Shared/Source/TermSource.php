<?php

namespace TransLangs\Shared\Source;
use TransLangs\Shared\Source\SourceRecord;

use InvalidArgumentException;

/**
 * Type-3 source: a taxonomy term field (name or description), keyed by
 * term_taxonomy_id. A term has no context and no source URL.
 */
final class TermSource extends SourceRecord
{
    private const FIELDS = ['name', 'description'];

    public function __construct(
        private int    $termTaxonomyId,
        private string $field,
        private string $text
    ) {
        if ($termTaxonomyId <= 0) {
            throw new InvalidArgumentException('TermSource: term_taxonomy_id must be positive.');
        }
        if (!in_array($field, self::FIELDS, true)) {
            throw new InvalidArgumentException("TermSource: unsupported field '{$field}'.");
        }
    }

    public function field(): string
    {
        return $this->field;
    }

    public function table(): string
    {
        return 'tl_term';
    }

    public function originalText(): string
    {
        return $this->text;
    }

    public function identity(): array
    {
        return [
            'term_taxonomy_id' => $this->termTaxonomyId,
            'field'            => $this->field,
            'original_hash'    => $this->originalHash(),
        ];
    }
}

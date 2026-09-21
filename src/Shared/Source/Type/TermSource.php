<?php

namespace LingoWP\Shared\Source\Type;
use LingoWP\Shared\Source\SourceRecord;

use InvalidArgumentException;

final class TermSource extends SourceRecord
{
    private const FIELDS = ['name', 'description'];

    private int    $termTaxonomyId;
    private string $field;
    private string $text;

    public function __construct(
        int    $termTaxonomyId,
        string $field,
        string $text
    ) {
        $this->termTaxonomyId = $termTaxonomyId;
        $this->field          = $field;
        $this->text           = $text;

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
        return 'term';
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

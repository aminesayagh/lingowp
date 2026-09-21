<?php

namespace LingoWP\Shared\Source\Type;
use LingoWP\Shared\Source\SourceRecord;

use InvalidArgumentException;

final class UserSource extends SourceRecord
{
    private const FIELDS = ['description'];

    private int    $userId;
    private string $field;
    private string $text;

    public function __construct(
        int    $userId,
        string $field,
        string $text
    ) {
        $this->userId = $userId;
        $this->field  = $field;
        $this->text   = $text;

        if ($userId <= 0) {
            throw new InvalidArgumentException('UserSource: user_id must be positive.');
        }
        if (!in_array($field, self::allowedFields(), true)) {
            throw new InvalidArgumentException("UserSource: unsupported field '{$field}'.");
        }
    }

    public static function allowedFields(): array
    {
        $fields = function_exists('apply_filters')
            ? apply_filters('lingowp_user_source_fields', self::FIELDS)
            : self::FIELDS;

        return array_values(array_filter(
            array_map('strval', is_array($fields) ? $fields : self::FIELDS),
            static fn(string $field): bool => $field !== ''
        ));
    }

    public function field(): string
    {
        return $this->field;
    }

    public function table(): string
    {
        return 'user';
    }

    public function originalText(): string
    {
        return $this->text;
    }

    public function identity(): array
    {
        return [
            'user_id'       => $this->userId,
            'field'         => $this->field,
            'original_hash' => $this->originalHash(),
        ];
    }
}

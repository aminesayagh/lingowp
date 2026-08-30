<?php

namespace LingoWP\Shared\Source\Type;
use LingoWP\Shared\Source\SourceRecord;

use InvalidArgumentException;

/**
 * User source: the author bio (`description` user meta), keyed by user_id.
 * `field` is always 'description' — there is only one scannable user field
 * today; kept as a column (rather than folded into the identity shape) so a
 * second field could be added later without a schema change.
 */
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
        if (!in_array($field, self::FIELDS, true)) {
            throw new InvalidArgumentException("UserSource: unsupported field '{$field}'.");
        }
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

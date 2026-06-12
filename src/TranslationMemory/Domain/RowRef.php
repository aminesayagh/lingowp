<?php

namespace TransLangs\TranslationMemory\Domain;

use InvalidArgumentException;

/**
 * Opaque handle for a single row in a wp_tl_translations_* table.
 *
 * Format: "<table>:<id>"  e.g. "post:123", "postmeta:88", "terms:14", "i18n:902", "html:55".
 * No controller or service should parse the string manually — use parse() once,
 * then pass the RowRef object.
 */
final class RowRef
{
    private function __construct(
        public readonly SourceRoute $table,
        public readonly int $id,
    ) {
    }

    public static function create(SourceRoute $table, int $id): self
    {
        return new self($table, $id);
    }

    /** @throws InvalidArgumentException on malformed or unknown-table input */
    public static function parse(string $s): self
    {
        $pos = strpos($s, ':');
        if ($pos === false) {
            throw new InvalidArgumentException("Invalid RowRef '{$s}': missing ':' separator");
        }

        $tableStr = substr($s, 0, $pos);
        $idStr = substr($s, $pos + 1);

        $table = SourceRoute::tryFrom($tableStr);
        if ($table === null) {
            throw new InvalidArgumentException("Invalid RowRef '{$s}': unknown table '{$tableStr}'");
        }

        $id = filter_var($idStr, FILTER_VALIDATE_INT);
        if ($id === false || (int) $id < 1) {
            throw new InvalidArgumentException("Invalid RowRef '{$s}': id must be a positive integer");
        }

        return new self($table, (int) $id);
    }

    public function toString(): string
    {
        return $this->table->value . ':' . $this->id;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /** Fully-qualified table name, e.g. wp_tl_translations_post. */
    public function tableName(): string
    {
        return $this->table->tableName();
    }
}

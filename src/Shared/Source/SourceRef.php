<?php

namespace LingoWP\Shared\Source;

use InvalidArgumentException;

final class SourceRef
{
    private const TABLES = [
        'post'      => 'post',
        'meta'      => 'meta',
        'term'      => 'term',
        'html'      => 'html',
        'option'    => 'option',
        'user'      => 'user',
        'term_meta' => 'term_meta',
    ];

    public string $kind;
    public int $id;

    private function __construct(
        string $kind,
        int $id
    ) {
        $this->kind = $kind;
        $this->id   = $id;
    }

    public static function make(string $kind, int $id): self
    {
        if (!isset(self::TABLES[$kind])) {
            throw new InvalidArgumentException("SourceRef: unknown kind '{$kind}'.");
        }
        if ($id <= 0) {
            throw new InvalidArgumentException('SourceRef: id must be positive.');
        }

        return new self($kind, $id);
    }

    public static function kindForTable(string $tableKey): string
    {
        $kind = array_search($tableKey, self::TABLES, true);
        if ($kind === false) {
            throw new InvalidArgumentException("SourceRef: unknown table '{$tableKey}'.");
        }

        return $kind;
    }

    public static function parse(string $ref): ?self
    {
        if (!preg_match('/^([a-zA-Z_]+):([0-9]+)$/', $ref, $m)) {
            return null;
        }
        if (!isset(self::TABLES[$m[1]])) {
            return null;
        }

        return new self($m[1], (int) $m[2]);
    }

    public function format(): string
    {
        return $this->kind . ':' . $this->id;
    }

    public function parentTableKey(): string
    {
        return self::TABLES[$this->kind];
    }
}

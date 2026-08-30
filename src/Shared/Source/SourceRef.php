<?php

namespace LingoWP\Shared\Source;

use InvalidArgumentException;

/**
 * Opaque identifier for one parent source row as it travels over the REST wire
 * and through the repository: `{kind}:{parent_id}`, e.g. `post:123`, `meta:45`.
 *
 * The kind token selects the parent/child table pair (there is no numeric type);
 * the id is the parent row's auto-increment primary key (always present once a
 * source is discovered).
 */
final class SourceRef
{
    /** kind token => parent table key (TranslationMemorySchema::parentTables() index). */
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

    /** The SourceRef kind token for a parent table key, e.g. 'post' => 'post'. */
    public static function kindForTable(string $tableKey): string
    {
        $kind = array_search($tableKey, self::TABLES, true);
        if ($kind === false) {
            throw new InvalidArgumentException("SourceRef: unknown table '{$tableKey}'.");
        }

        return $kind;
    }

    /** Parse `{kind}:{id}`, or null if malformed (lets callers 400 cleanly). */
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

    /** Parent table key (unprefixed), e.g. 'post'. */
    public function parentTableKey(): string
    {
        return self::TABLES[$this->kind];
    }
}

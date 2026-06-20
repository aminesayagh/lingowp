<?php

namespace TransLangs\Source\Domain;

use InvalidArgumentException;

/**
 * Opaque identifier for one parent source row as it travels over the REST wire
 * and through the repository: `{kind}:{parent_id}`, e.g. `post:123`, `meta:45`,
 * `staticText:9`. This replaces the v4 hex source_key.
 *
 * The kind token selects the parent/child table pair (there is no numeric type);
 * the id is the parent row's auto-increment primary key (always present once a
 * source is discovered).
 */
final class SourceRef
{
    /** kind token => parent table key (TranslationMemorySchema::parentTables() index). */
    private const TABLES = [
        'post'       => 'tl_post',
        'meta'       => 'tl_meta',
        'term'       => 'tl_term',
        'staticText' => 'tl_staticText',
        'html'       => 'tl_html',
        'option'     => 'tl_option',
    ];

    private function __construct(
        public readonly string $kind,
        public readonly int $id
    ) {}

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

    /** The SourceRef kind token for a parent table key, e.g. 'tl_post' => 'post'. */
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
        if (!preg_match('/^([a-zA-Z]+):([0-9]+)$/', $ref, $m)) {
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

    /** Parent table key (unprefixed), e.g. 'tl_post'. */
    public function parentTableKey(): string
    {
        return self::TABLES[$this->kind];
    }
}

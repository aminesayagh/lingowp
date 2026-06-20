<?php

namespace TransLangs\Core\Source;
use TransLangs\Core\Text\TL_Key;
use TransLangs\Core\Text\IdentityKey;

/**
 * A discovered source string ready to be written to its v5 parent table.
 *
 * Each source type has a final subclass that speaks its own vocabulary
 * (PostSource::field(), PostMetaSource::metaKey()/context(), …) and declares:
 *   - table():    which parent table it belongs to (a TranslationMemorySchema key)
 *   - identity(): the uniq_source columns => values, including original_hash
 *
 * The base assembles the full insert row (identity + original_text) and the
 * original_hash. Because a subclass lists only the columns it legitimately owns,
 * a type cannot populate a column that does not apply to it. Discovery is
 * language-independent: a record is the same regardless of how many target
 * languages exist.
 */
abstract class SourceRecord
{
    /** Parent table key (TranslationMemorySchema::parentTables() index), e.g. 'tl_post'.
     * The table is the record's kind — there is no separate numeric type. */
    abstract public function table(): string;

    /** Raw original source text. */
    abstract public function originalText(): string;

    /**
     * The uniq_source columns for this record, including original_hash. This is
     * both the duplicate-detection identity and the WHERE used to resolve the
     * parent id after insert.
     *
     * @return array<string, mixed>
     */
    abstract public function identity(): array;

    /** original_hash = SHA-256(normalized original text), raw 32 bytes. */
    final public function originalHash(): string
    {
        return TL_Key::currentHash($this->originalText());
    }

    /**
     * Full parent column set for an insert: every identity column plus the
     * stored original_text. status and timestamps are added by the repository.
     *
     * @return array<string, mixed>
     */
    public function row(): array
    {
        return $this->identity() + ['original_text' => $this->originalText()];
    }

    /**
     * Stable in-memory de-duplication key for one discovery pass: the type plus
     * a hex digest of the identity columns. Not stored — render and the database
     * use the readable identity itself.
     */
    final public function dedupeKey(): string
    {
        return $this->table() . ':' . IdentityKey::of($this->identity());
    }
}

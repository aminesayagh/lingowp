<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * A discovered source string ready to be written to wp_tl_source.
 *
 * This is the v4 write-path base. Each source type has a final subclass that
 * speaks ITS OWN vocabulary (PostSource::field(), PostMetaSource::metaKey()/
 * context(), …). The raw DB columns — ref1, ref2, source_url — live ONLY inside
 * toRow() here; no business code mentions them. Because a subclass passes only
 * the columns it legitimately owns, a type cannot populate a column that does
 * not apply to it (a post has no ref2 and no source_url). This is the code layer
 * that protects the table's per-type shape (architecture §2).
 *
 * Discovery is language-independent: a record is the same regardless of how many
 * target languages exist.
 */
abstract class SourceRecord
{
    /** TL_Key::TYPE_* constant for this source type. */
    abstract public function type(): int;

    /** owner_id for the key + owner columns: post_id (1-2), term_taxonomy_id (3), null (4-5). */
    abstract public function ownerId(): ?int;

    /** Raw original source text. */
    abstract public function originalText(): string;

    /** Each type assembles its own row by calling toRow() with the columns it owns. */
    abstract public function row(): array;

    /**
     * The single place the raw columns are assembled. ref2 and source_url default
     * to null, so a type that does not pass them simply cannot set them. The
     * deterministic (locational) source_key and the mutable current_hash are
     * computed here via TL_Key.
     *
     * @return array<string, mixed>
     */
    final protected function toRow(string $ref1, ?string $ref2 = null, ?string $sourceUrl = null): array
    {
        return [
            'source_key'       => $this->buildKey($ref1, $ref2),
            'type'             => $this->type(),
            'post_id'          => $this->postOwner(),
            'term_taxonomy_id' => $this->termOwner(),
            'ref1'             => $ref1,
            'ref2'             => $ref2,
            'source_url'       => $sourceUrl,
            'current_hash'     => TL_Key::currentHash($this->originalText()),
            'original_text'    => $this->originalText(),
        ];
    }

    /**
     * The source_key for this record. Atomic types are locational (zero tail);
     * split fields override this to text-tail the key (SplitPostSource).
     */
    protected function buildKey(string $ref1, ?string $ref2): string
    {
        return TL_Key::make($this->type(), $this->ownerId(), $ref1, $ref2, $this->originalText());
    }

    /** The deterministic 32-byte source_key (raw bytes). */
    final public function key(): string
    {
        return $this->row()['source_key'];
    }

    private function postOwner(): ?int
    {
        return ($this->type() === TL_Key::TYPE_POST || $this->type() === TL_Key::TYPE_POSTMETA)
            ? $this->ownerId()
            : null;
    }

    private function termOwner(): ?int
    {
        return $this->type() === TL_Key::TYPE_TERMS ? $this->ownerId() : null;
    }
}

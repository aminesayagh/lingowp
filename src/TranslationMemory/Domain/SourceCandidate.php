<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * One discovered source string ready to be upserted into wp_tl_source.
 *
 * This is the v4 write-path value object: it carries exactly the §1 key inputs
 * plus the original text, and derives its source_key and original_hash through
 * the TL_Key chokepoint. Discovery is language-independent — a candidate is the
 * same regardless of how many target languages exist.
 */
final class SourceCandidate
{
    /**
     * @param int         $type         TL_Key::TYPE_* constant
     * @param int|null    $ownerId      post_id (types 1-2), term_taxonomy_id (type 3), null for 4-5
     * @param string      $ref1         field / meta_key / domain / selector (NOT NULL)
     * @param string|null $ref2         meta context / gettext context / reference (nullable)
     * @param string      $originalText raw source text
     */
    public function __construct(
        public readonly int     $type,
        public readonly ?int    $ownerId,
        public readonly string  $ref1,
        public readonly ?string $ref2,
        public readonly string  $originalText,
    ) {}

    /** The deterministic 32-byte source_key (raw bytes). */
    public function key(): string
    {
        return TL_Key::make($this->type, $this->ownerId, $this->ref1, $this->ref2, $this->originalText);
    }

    /** Full 32-byte SHA-256 of the normalized original text (raw bytes). */
    public function originalHash(): string
    {
        return TL_Key::originalHash($this->originalText);
    }

    /** Owner id for the post_id column (types 1-2 only). */
    public function postId(): ?int
    {
        return ($this->type === TL_Key::TYPE_POST || $this->type === TL_Key::TYPE_POSTMETA)
            ? $this->ownerId
            : null;
    }

    /** Owner id for the term_taxonomy_id column (type 3 only). */
    public function termTaxonomyId(): ?int
    {
        return $this->type === TL_Key::TYPE_TERMS ? $this->ownerId : null;
    }
}

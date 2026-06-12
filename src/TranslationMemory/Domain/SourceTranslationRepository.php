<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * The v4 translation persistence contract: reads and writes wp_tl_source and
 * wp_tl_translation, keyed by the deterministic source_key (architecture §2).
 *
 * Discovery is language-independent — one source row per string, no per-language
 * placeholders. Translation rows (added to this contract in later sub-batches)
 * exist only when a translation has actually been produced; absence means
 * "not yet translated."
 *
 * This interface grows one sub-batch at a time. B2a declares the structured
 * write path (save-time discovery for types 1-3).
 */
interface SourceTranslationRepository
{
    /**
     * Idempotently insert source rows (INSERT … ON DUPLICATE KEY UPDATE on
     * uniq_key). Re-discovering an unchanged string is a no-op.
     *
     * @param SourceCandidate[] $candidates
     * @return int Rows newly inserted.
     */
    public function upsertSources(array $candidates): int;

    /**
     * Delete this post's source rows (types 1 AND 2 — both ride post_id) whose
     * source_key is not in $currentKeys. The FK cascade clears their
     * translations. An empty $currentKeys deletes all of the post's sources.
     *
     * @param string[] $currentKeys Raw 32-byte source keys still present.
     */
    public function deleteStaleSourcesForPost(int $postId, array $currentKeys): void;

    /**
     * Delete this term's source rows (type 3) whose source_key is not in
     * $currentKeys. The FK cascade clears their translations.
     *
     * @param string[] $currentKeys Raw 32-byte source keys still present.
     */
    public function deleteStaleSourcesForTerm(int $termTaxonomyId, array $currentKeys): void;
}

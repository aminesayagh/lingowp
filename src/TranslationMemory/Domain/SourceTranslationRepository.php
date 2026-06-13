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
 * Entity deletion is handled by InnoDB foreign keys (wp_posts /
 * wp_term_taxonomy → source → translation). Because keys are locational (D26),
 * editing a string's text updates its row in place (same key) — the delete-stale
 * methods remove ONLY rows whose location genuinely disappeared (a field/meta
 * key removed, a repeater item deleted), never edits.
 */
interface SourceTranslationRepository
{
    /**
     * Upsert source rows by their locational key. A new location inserts; an
     * existing location whose text changed updates original_text + current_hash
     * in place (D27 — translations stay attached). Each record supplies its full
     * column payload via SourceRecord::row().
     *
     * @param SourceRecord[] $records
     * @return int Rows affected (inserts + in-place updates).
     */
    public function upsertSources(array $records): int;

    /**
     * Render read path: fetch produced translations for a set of locational keys
     * in one language. Only renderable statuses (machine / reviewed /
     * needs_review) are returned. Join-free — keys are computed by the caller.
     *
     * @param  string[] $rawKeys Raw 32-byte source keys.
     * @return array<string, string> hex(source_key) => translated_text
     */
    public function fetchTranslations(array $rawKeys, string $langCode): array;

    /**
     * Delete this post's source rows (types 1 AND 2 — both ride post_id) whose
     * locational key is not in $currentKeys. FK cascade clears their
     * translations. Empty $currentKeys deletes all of the post's sources.
     *
     * @param string[] $currentKeys Raw 32-byte source keys still present.
     */
    public function deleteStaleSourcesForPost(int $postId, array $currentKeys): void;

    /**
     * Delete this post+meta-key's type-2 source rows whose key is not in
     * $currentKeys (reconciles one meta key — e.g. a removed repeater path).
     * Empty $currentKeys deletes all source rows for that post + meta key.
     *
     * @param string[] $currentKeys Raw 32-byte source keys still present.
     */
    public function deleteStaleSourcesForPostMetaKey(int $postId, string $metaKey, array $currentKeys): void;

    /**
     * Delete this term's source rows (type 3) whose key is not in $currentKeys.
     * FK cascade clears their translations.
     *
     * @param string[] $currentKeys Raw 32-byte source keys still present.
     */
    public function deleteStaleSourcesForTerm(int $termTaxonomyId, array $currentKeys): void;
}

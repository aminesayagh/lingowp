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
     * All produced type-5 (HTML) translations for one language — a join-free
     * prefix range scan on the clustered PK (all type-5 keys start with byte 5).
     * Used to build the render-time type-5 map (empty ⇒ skip the DOM parse).
     *
     * @return array<string, string> hex(source_key) => translated_text
     */
    public function fetchHtmlTranslations(string $langCode): array;

    // ── Path D: translation writes (admin) ────────────────────────────────────

    /**
     * Create or update one (source, language) translation. translated_against_hash
     * is stamped server-side from the source's current_hash — never from the
     * caller. No-op if the source does not exist.
     */
    public function saveTranslation(string $rawKey, string $langCode, string $text, int $status, ?string $provider): void;

    /** Remove one (source, language) translation — the string returns to untranslated. */
    public function deleteTranslation(string $rawKey, string $langCode): void;

    /**
     * Save the same translation into every given source key (apply-everywhere).
     * With $overwrite = false, keys that already have a translation are skipped.
     *
     * @param string[] $rawKeys
     * @return int Keys written.
     */
    public function applyTranslationToKeys(array $rawKeys, string $langCode, string $text, int $status, bool $overwrite): int;

    /** Mark a source ignored (status 3) and delete all its translations, atomically (D1). */
    public function ignoreSource(string $rawKey): void;

    /**
     * Return ignored sources to discovered (status 0); translations rebuild on demand.
     *
     * @param string[] $rawKeys
     */
    public function restoreSources(array $rawKeys): void;

    /** updated_at of one (source, language) translation, for the concurrent-edit guard. */
    public function translationUpdatedAt(string $rawKey, string $langCode): ?string;

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

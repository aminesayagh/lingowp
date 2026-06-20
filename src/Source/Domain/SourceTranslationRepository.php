<?php

namespace TransLangs\Source\Domain;

/**
 * The v5 translation persistence contract — per-type, with TWO child-key models.
 *
 *   hash-link (post, term, html): child keyed (original_hash, lang_code); a
 *     translation is shared by every parent position with the same text. Render
 *     reads it by hash (no parent join); ignore/delete are text-global.
 *   parent_id (meta, staticText): child keyed (parent_id, lang_code); the
 *     position owns its translation. Render reads it by joining the parent.
 *
 * There is no translated_against_hash and no stale parent status: a source-text
 * change deletes the stale parent and (Phase 5) seeds the new translation as
 * needs_review. Ignore sets parent.status=-1 AND deletes the children, so render's
 * "no child ⇒ original" covers untranslated and ignored alike. Adding a language
 * creates zero rows. Languages stay option-backed (lang_code is a plain column).
 */
interface SourceTranslationRepository
{
    /**
     * Upsert parent rows (INSERT IGNORE on uniq_source), grouped by table. A new
     * identity inserts; an existing one is left as-is (identity includes
     * original_hash, so same identity = same text). No child rows are touched.
     *
     * @param SourceRecord[] $records
     * @return int Rows inserted.
     */
    public function upsertSources(array $records): int;

    /**
     * Discover gettext plural messages: ensure each singular anchor exists in
     * tl_staticText, then link the plural original via tl_staticPluralText.
     *
     * @param array<int, array{domain:string, context:?string, single:string, plural:string}> $candidates
     * @return int Plural rows inserted.
     */
    public function upsertPluralSources(array $candidates): int;

    /**
     * Render read for hash-link types (post/term/html): the produced translations
     * for a set of original hashes in one language, from that type's child table.
     * Keyed by lowercase hex(original_hash) => translated_text.
     *
     * @param string   $parentTableKey TranslationMemorySchema key, e.g. 'tl_post'
     * @param string[] $hashes         raw 32-byte original hashes
     * @return array<string, string>
     */
    public function fetchHashTranslations(string $parentTableKey, array $hashes, string $langCode): array;

    /**
     * Every produced translation for a hash-link type in one language, keyed by
     * lowercase hex(original_hash) => translated_text. For the HTML fallback,
     * where the lookup hashes are only known after walking the page DOM.
     *
     * @return array<string, string>
     */
    public function fetchAllHashTranslations(string $parentTableKey, string $langCode): array;


    /**
     * Render read for parent_id types (meta/staticText): join each parent lookup
     * (parent.status = 0) to its child for $langCode. Keyed by
     * IdentityKey::of(lookup) => translated_text.
     *
     * @param string                           $parentTableKey e.g. 'tl_meta'
     * @param array<int, array<string,scalar>> $lookups        list of identity-column maps
     * @return array<string, string>
     */
    public function fetchParentTranslations(string $parentTableKey, array $lookups, string $langCode): array;

    /**
     * Render read for gettext plurals: every translated form per message in one
     * language, keyed IdentityKey(lookup) => [form_index => translated_text].
     *
     * @param array<int, array<string,scalar>> $lookups singular staticText identities
     * @return array<string, array<int, string>>
     */
    public function fetchPluralForms(array $lookups, string $langCode): array;

    // ── translation writes (admin) ─────────────────────────────────────────────

    /**
     * Create or update one translation for the parent addressed by $ref.
     * hash-link: keyed (parent.original_hash, lang) — shared across positions.
     * parent_id: keyed (parent.id, lang). No-op if the parent row is gone.
     */
    public function saveTranslation(SourceRef $ref, string $langCode, string $text, int $status, ?string $provider): void;

    /**
     * Create or update one gettext plural FORM for the message anchored by $ref,
     * keyed (parent_id, lang_code, form_index). Singular saves use saveTranslation.
     */
    public function savePluralForm(SourceRef $ref, string $langCode, int $formIndex, string $text, int $status, ?string $provider): void;

    /** Remove a single gettext plural FORM for $ref + language. */
    public function deletePluralForm(SourceRef $ref, string $langCode, int $formIndex): void;

    /**
     * The translated plural forms of $ref for one language, keyed
     * form_index => translated_text. For the admin plural editor.
     *
     * @return array<int, string>
     */
    public function loadPluralForms(SourceRef $ref, string $langCode): array;

    /** Remove the translation for $ref + language (hash-link delete is text-global). */
    public function deleteTranslation(SourceRef $ref, string $langCode): void;

    /**
     * Apply the same translation to several parents (apply-everywhere). Mostly for
     * parent_id types — hash-link positions already share by text.
     *
     * @param SourceRef[] $refs
     * @return int Translations written.
     */
    public function applyTranslationToRefs(array $refs, string $langCode, string $text, int $status, bool $overwrite): int;

    /** Mark a parent ignored (status -1) AND delete its child(ren) across languages. */
    public function ignoreSource(SourceRef $ref): void;

    /**
     * Return ignored parents to discovered (status 0). Children were deleted on
     * ignore, so the string is untranslated until re-translated.
     *
     * @param SourceRef[] $refs
     */
    public function restoreSources(array $refs): void;

    /** updated_at of $ref's translation for the concurrent-edit guard, or null. */
    public function translationUpdatedAt(SourceRef $ref, string $langCode): ?string;

    // ── discovery reconciliation (delete-stale; carry-forward in Phase 5) ───────

    /**
     * Reconcile a post's parents (post fields + meta) after re-discovery: upsert
     * the current $records, then delete this post's still-discovered parents that
     * are absent from $records. Empty $records deletes all of the post's parents.
     *
     * @param SourceRecord[] $records
     */
    public function reconcilePost(int $postId, array $records): void;

    /**
     * Reconcile one post + meta key's parents. Same delete-stale rule, scoped.
     *
     * @param SourceRecord[] $records
     */
    public function reconcilePostMetaKey(int $postId, string $metaKey, array $records): void;

    /**
     * Reconcile a term's parents.
     *
     * @param SourceRecord[] $records
     */
    public function reconcileTerm(int $termTaxonomyId, array $records): void;
}

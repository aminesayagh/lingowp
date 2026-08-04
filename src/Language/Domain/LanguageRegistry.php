<?php

namespace TransLangs\Language\Domain;

/**
 * Authoritative source for which languages are active in this installation.
 *
 * Languages are identified by canonical WordPress locale everywhere (no numeric id).
 * Config is two pipe-delimited code lists stored as options:
 *   - active   languages currently enabled as translation targets
 *   - existing every target ever added (append-only roster; active ⊆ existing)
 *
 * Per-language display and routing metadata is stored in the language metadata
 * option and sourced from WordPress's Site Language catalog when created.
 *
 * The source locale and its display metadata are captured once during setup.
 * Only TARGET languages live in the active/existing language lists.
 */
interface LanguageRegistry
{
    /** Frozen canonical source locale, e.g. 'en_US'. */
    public function getDefaultLanguage(): string;

    /** @return array{code: string, language: string, region: string, name: string, display_name: string, direction: string} */
    public function getDefaultLanguageDetails(): array;

    /**
     * Active target language codes, in stored order.
     *
     * @return string[]
     */
    public function getTargetLanguages(): array;

    /**
     * Active target language metadata (from the SupportedLanguages catalog),
     * keyed by language code, in stored order.
     *
     * @return array<string, array{name: string, native: string, dir: string}>
     */
    public function getTargetLanguageDetails(): array;

    /**
     * Source language + active target languages.
     * Use this for URL routing, switchers, hreflang, and link rewriting.
     *
     * @return string[]
     */
    public function getEnabledLanguages(): array;

    /** Returns true for the source language and any active target language. */
    public function isEnabledLanguage(string $code): bool;

    /** Public URL slug for an enabled locale. */
    public function slugForLanguage(string $code): string;

    /** Enabled locale identified by a public URL slug, or null. */
    public function languageForSlug(string $slug): ?string;

    /**
     * Every target language ever added, active or soft-deleted (the permanent
     * roster). Used by the settings screen and by hard-delete cleanup.
     *
     * @return string[]
     */
    public function existingLanguages(): array;

    /**
     * Add or re-enable a target language by code.
     * Idempotent: adds the code to the active set and to the existing roster.
     * No-op for the source language or an unknown code.
     */
    public function enableLanguage(string $code): void;

    /**
     * Soft-delete a target language: remove it from the active set but keep it
     * in the existing roster (so re-enabling is instant and its translations,
     * keyed by code, are untouched). No-op if the code is not active.
     */
    public function disableLanguage(string $code): void;

    /**
     * Ordered fallback languages to try after $code when a translation is
     * missing, before falling back to the untranslated source content.
     * Follows each language's configured fallback_language pointer; stops at
     * an empty pointer, a repeat (cycle/self-reference guard), or the
     * default/source language (which has no translation rows of its own).
     *
     * @return string[]
     */
    public function fallbackChain(string $code): array;
}

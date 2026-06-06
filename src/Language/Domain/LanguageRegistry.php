<?php

namespace TransLangs\Language\Domain;

/**
 * Authoritative source for which languages are active in this installation.
 *
 * Source language is never stored — it is always read from get_locale().
 * Only TARGET languages live in the persistence layer.
 */
interface LanguageRegistry
{
    /** Normalized source language from get_locale(), e.g. 'en'. Never in storage. */
    public function getDefaultLanguage(): string;

    /**
     * Active target languages from storage, ordered by sort_order then code.
     *
     * @return string[]
     */
    public function getTargetLanguages(): array;

    /**
     * Active target language metadata from storage, keyed by language code.
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

    /**
     * Add or re-enable a target language.
     * Idempotent: calling for an already-active language only refreshes metadata.
     */
    public function enableLanguage(
        string $code,
        string $name,
        string $nativeName,
        string $direction,
        int    $sortOrder = 0
    ): void;

    /**
     * Remove a target language and cascade-delete all its translations.
     * No-op if the language is not present.
     */
    public function disableLanguage(string $code): void;
}

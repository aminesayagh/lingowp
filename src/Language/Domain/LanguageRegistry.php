<?php

namespace LingoWP\Language\Domain;

interface LanguageRegistry
{
    public function getDefaultLanguage(): string;

    public function getDefaultLanguageDetails(): array;

    public function getTargetLanguages(): array;

    public function getTargetLanguageDetails(): array;

    public function getEnabledLanguages(): array;

    public function isEnabledLanguage(string $code): bool;

    public function slugForLanguage(string $code): string;

    public function languageForSlug(string $slug): ?string;

    public function existingLanguages(): array;

    public function enableLanguage(string $code): void;

    public function disableLanguage(string $code): void;

    public function fallbackChain(string $code): array;

    public function resolutionChain(string $code): array;
}

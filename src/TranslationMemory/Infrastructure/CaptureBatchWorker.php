<?php

namespace TransLangs\TranslationMemory\Infrastructure;

use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\TranslationMemory\Domain\SourceRoute;
use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;

final class CaptureBatchWorker
{
    public function __construct(
        private LanguageRegistry $languageRegistry,
        private TranslationMemoryRepository $translationMemory,
        private OwnedStringIndex $ownedStringIndex
    ) {}

    public function register(): void
    {
        add_action(CaptureBatchQueue::HOOK, [$this, 'handle'], 10, 1);
    }

    /**
     * Runs later in Action Scheduler, outside the frontend response.
     */
    public function handle(string $batch_id): void
    {
        $batchId = $batch_id;

        if (!$this->isValidBatchId($batchId)) {
            return;
        }

        $transient = CaptureBatchQueue::transientName($batchId);
        $payload   = get_transient($transient);

        delete_transient($transient);

        if (!is_array($payload)) {
            return;
        }

        $this->insertCapturedCandidates($payload);
    }

    private function isValidBatchId(string $batchId): bool
    {
        return (bool) preg_match('/\\A[a-f0-9-]{32,36}\\z/i', $batchId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertCapturedCandidates(array $payload): void
    {
        $gettextIdentities = $this->uniqueIdentities(
            $this->gettextIdentities($this->payloadCandidates($payload, 'gettext_candidates'))
        );
        $fallbackIdentities = $this->unownedFallbackIdentities(
            $this->uniqueIdentities(
                $this->fallbackIdentities($this->payloadCandidates($payload, 'fallback_candidates'))
            )
        );

        if ($gettextIdentities === [] && $fallbackIdentities === []) {
            return;
        }

        $identities = array_merge($gettextIdentities, $fallbackIdentities);
        $sourceUrl   = $this->payloadString($payload, 'source_url');
        $requestArea = $this->payloadString($payload, 'request_area');

        foreach ($this->languageRegistry->getTargetLanguages() as $targetLang) {
            $this->translationMemory->insertMissingStrings($identities, $targetLang);
            $this->translationMemory->recordI18nObservations($identities, $targetLang, $sourceUrl, $requestArea);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, mixed>
     */
    private function payloadCandidates(array $payload, string $key): array
    {
        return isset($payload[$key]) && is_array($payload[$key]) ? $payload[$key] : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadString(array $payload, string $key): string
    {
        $value = isset($payload[$key]) && is_scalar($payload[$key])
            ? trim((string) $payload[$key])
            : '';

        if ($key === 'request_area') {
            return substr($value !== '' ? $value : 'frontend', 0, 20);
        }

        return $value;
    }

    /**
     * @param TranslationSourceIdentity[] $identities
     * @return TranslationSourceIdentity[]
     */
    private function uniqueIdentities(array $identities): array
    {
        $unique = [];
        $seen   = [];

        foreach ($identities as $identity) {
            $key = $identity->routeTable->value . ':'
                 . ($identity->source ?? '') . ':'
                 . bin2hex((string) $identity->referenceHash) . ':'
                 . bin2hex($identity->originalHash);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $identity;
        }

        return $unique;
    }

    /**
     * @param array<int, mixed> $candidates
     * @return TranslationSourceIdentity[]
     */
    private function gettextIdentities(array $candidates): array
    {
        $identities = [];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $identity = $this->gettextIdentity($candidate);
            if ($identity !== null) {
                $identities[] = $identity;
            }
        }

        return $identities;
    }

    /**
     * @param TranslationSourceIdentity[] $identities
     * @return TranslationSourceIdentity[]
     */
    private function unownedFallbackIdentities(array $identities): array
    {
        $unowned = [];

        foreach ($identities as $identity) {
            if ($this->ownedStringIndex->existsOwnedHash($identity->originalHash)) {
                continue;
            }

            $unowned[] = $identity;
        }

        return $unowned;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function gettextIdentity(array $candidate): ?TranslationSourceIdentity
    {
        if (!isset($candidate['original_text']) || !is_scalar($candidate['original_text'])) {
            return null;
        }

        $originalText = (string) $candidate['original_text'];
        $normalized   = StringNormalizer::normalize($originalText);
        if ($normalized === '') {
            return null;
        }

        $domain = isset($candidate['domain']) && is_scalar($candidate['domain'])
            ? $this->domain((string) $candidate['domain'])
            : 'default';

        return new TranslationSourceIdentity(
            SourceRoute::I18n,
            0,
            null,
            null,
            null,
            null,
            null,
            'gettext',
            $domain,
            StringHashGenerator::hashReference($domain),
            $originalText,
            $normalized,
            StringHashGenerator::hashNormalized($normalized)
        );
    }

    /**
     * @param array<int, mixed> $candidates
     * @return TranslationSourceIdentity[]
     */
    private function fallbackIdentities(array $candidates): array
    {
        $identities = [];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $identity = $this->fallbackIdentity($candidate);
            if ($identity !== null) {
                $identities[] = $identity;
            }
        }

        return $identities;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function fallbackIdentity(array $candidate): ?TranslationSourceIdentity
    {
        if (!isset($candidate['original_text']) || !is_scalar($candidate['original_text'])) {
            return null;
        }

        $originalText = (string) $candidate['original_text'];
        $normalized   = StringNormalizer::normalize($originalText);
        if ($normalized === '') {
            return null;
        }

        $selector = isset($candidate['selector']) && is_scalar($candidate['selector'])
            ? $this->selector((string) $candidate['selector'])
            : '';

        return new TranslationSourceIdentity(
            SourceRoute::I18n,
            0,
            null,
            null,
            null,
            null,
            null,
            'rendered_html',
            $selector,
            StringHashGenerator::hashReference($selector),
            $originalText,
            $normalized,
            StringHashGenerator::hashNormalized($normalized)
        );
    }

    private function selector(string $selector): string
    {
        $selector = strtolower(trim($selector));

        return preg_replace('/[^a-z0-9>_-]/', '', $selector) ?? '';
    }

    private function domain(string $domain): string
    {
        $domain = trim($domain);

        return $domain !== '' ? substr($domain, 0, 191) : 'default';
    }
}

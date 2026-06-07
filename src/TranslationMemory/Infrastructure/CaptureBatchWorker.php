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
        private StringNormalizer $normalizer,
        private StringHashGenerator $hashGenerator
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

        $this->insertFallbackCandidates($payload);
    }

    private function isValidBatchId(string $batchId): bool
    {
        return (bool) preg_match('/\\A[a-f0-9-]{32,36}\\z/i', $batchId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertFallbackCandidates(array $payload): void
    {
        if (!isset($payload['fallback_candidates']) || !is_array($payload['fallback_candidates'])) {
            return;
        }

        $identities = $this->fallbackIdentities($payload['fallback_candidates']);
        if ($identities === []) {
            return;
        }

        foreach ($this->languageRegistry->getTargetLanguages() as $targetLang) {
            $this->translationMemory->insertMissingStrings($identities, $targetLang);
        }
    }

    /**
     * @param array<int, mixed> $candidates
     * @return TranslationSourceIdentity[]
     */
    private function fallbackIdentities(array $candidates): array
    {
        $identities = [];
        $seen       = [];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $identity = $this->fallbackIdentity($candidate);
            if ($identity === null) {
                continue;
            }

            $key = bin2hex((string) $identity->referenceHash) . ':' . bin2hex($identity->originalHash);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key]  = true;
            $identities[] = $identity;
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
        $normalized   = $this->normalizer->normalize($originalText);
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
            $this->hashGenerator->hashReference($selector),
            $originalText,
            $normalized,
            $this->hashGenerator->hashNormalized($normalized)
        );
    }

    private function selector(string $selector): string
    {
        $selector = strtolower(trim($selector));

        return preg_replace('/[^a-z0-9>_-]/', '', $selector) ?? '';
    }
}

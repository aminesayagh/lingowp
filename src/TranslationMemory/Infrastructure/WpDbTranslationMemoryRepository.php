<?php

namespace TransLangs\TranslationMemory\Infrastructure;

use TransLangs\TranslationMemory\Domain\RowRef;
use TransLangs\TranslationMemory\Domain\SourceRoute;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;
use TransLangs\TranslationMemory\Domain\TranslationStatus;
use TransLangs\TranslationMemory\Infrastructure\Repositories\AdminTranslationsQuery;
use TransLangs\TranslationMemory\Infrastructure\Repositories\HtmlTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\I18nTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\PostmetaTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\PostTranslationsRepository;
use TransLangs\TranslationMemory\Infrastructure\Repositories\TermsTranslationsRepository;

/**
 * Facade over the four split table repositories.
 *
 * Routes every operation to the correct table repository based on
 * RowRef::$table (SourceRoute enum) or the identity's routeTable field.
 * Never queries the DB directly — all SQL lives in the split repos.
 */
final class WpDbTranslationMemoryRepository implements TranslationMemoryRepository
{
    public function __construct(
        private PostTranslationsRepository     $postRepo,
        private PostmetaTranslationsRepository $postmetaRepo,
        private TermsTranslationsRepository    $termsRepo,
        private I18nTranslationsRepository     $i18nRepo,
        private HtmlTranslationsRepository     $htmlRepo,
        private AdminTranslationsQuery         $adminQuery,
    ) {}

    public function insertMissingStrings(array $identities, string $targetLang): int
    {
        if ($identities === []) {
            return 0;
        }

        // Pre-group once to avoid 4× array traversal inside each repo.
        $byRoute = [];
        foreach ($identities as $identity) {
            $byRoute[$identity->routeTable->value][] = $identity;
        }

        $count = 0;

        if (isset($byRoute[SourceRoute::Post->value])) {
            $count += $this->postRepo->insertMissing($byRoute[SourceRoute::Post->value], $targetLang);
        }
        if (isset($byRoute[SourceRoute::Postmeta->value])) {
            $count += $this->postmetaRepo->insertMissing($byRoute[SourceRoute::Postmeta->value], $targetLang);
        }
        if (isset($byRoute[SourceRoute::Terms->value])) {
            $count += $this->termsRepo->insertMissing($byRoute[SourceRoute::Terms->value], $targetLang);
        }
        if (isset($byRoute[SourceRoute::I18n->value])) {
            $count += $this->i18nRepo->insertMissing($byRoute[SourceRoute::I18n->value], $targetLang);
        }
        if (isset($byRoute[SourceRoute::Html->value])) {
            $count += $this->htmlRepo->insertMissing($byRoute[SourceRoute::Html->value], $targetLang);
        }

        return $count;
    }

    public function recordHtmlObservations(
        array $identities,
        string $targetLang,
        string $sourceUrl
    ): void {
        $this->htmlRepo->recordObservations($identities, $targetLang, $sourceUrl);
    }

    public function deleteStalePostStrings(int $postId, array $currentHashesRaw): void
    {
        $this->postRepo->deleteStaleForPost($postId, $currentHashesRaw);
    }

    public function deleteStalePostmetaStrings(int $postId, array $currentHashesRaw): void
    {
        $this->postmetaRepo->deleteStaleForPost($postId, $currentHashesRaw);
    }

    public function saveTranslation(RowRef $rowRef, string $text, int $status): void
    {
        $this->repoFor($rowRef)->save($rowRef->id, $text, $status);
    }

    public function markDiscovered(RowRef $rowRef): void
    {
        $this->repoFor($rowRef)->updateStatus($rowRef->id, TranslationStatus::DISCOVERED);
    }

    public function markReviewed(RowRef $rowRef): void
    {
        $this->repoFor($rowRef)->updateStatus($rowRef->id, TranslationStatus::REVIEWED);
    }

    public function markNeedsReview(RowRef $rowRef): void
    {
        $this->repoFor($rowRef)->updateStatus($rowRef->id, TranslationStatus::NEEDS_REVIEW);
    }

    public function ignoreString(RowRef $rowRef): void
    {
        $this->repoFor($rowRef)->updateStatus($rowRef->id, TranslationStatus::IGNORED);
    }

    public function find(RowRef $rowRef): ?array
    {
        return $this->repoFor($rowRef)->find($rowRef->id);
    }

    public function query(array $filters, int $page, int $perPage): array
    {
        return $this->adminQuery->query($filters, $page, $perPage);
    }

    public function sourceTypeCounts(array $filters): array
    {
        return $this->adminQuery->sourceTypeCounts($filters);
    }

    public function languageSiblings(RowRef $rowRef): array
    {
        return $this->adminQuery->languageSiblings($rowRef);
    }

    public function sameOriginalUntranslated(string $originalHashHex, string $targetLang): array
    {
        return $this->adminQuery->sameOriginalUntranslated($originalHashHex, $targetLang);
    }

    public function getPostTranslations(string $targetLang, int $postId): array
    {
        return $this->postRepo->getTranslations($targetLang, $postId);
    }

    public function getPostmetaTranslations(string $targetLang, int $postId): array
    {
        return $this->postmetaRepo->getTranslations($targetLang, $postId);
    }

    public function getTermTranslations(string $targetLang, array $termTaxonomyIds): array
    {
        return $this->termsRepo->getTranslations($targetLang, $termTaxonomyIds);
    }

    public function getI18nTranslations(string $targetLang, string $source): array
    {
        return $this->i18nRepo->getTranslations($targetLang, $source);
    }

    public function getHtmlTranslations(string $targetLang): array
    {
        return $this->htmlRepo->getTranslations($targetLang);
    }

    private function repoFor(RowRef $rowRef): PostTranslationsRepository|PostmetaTranslationsRepository|TermsTranslationsRepository|I18nTranslationsRepository|HtmlTranslationsRepository
    {
        return match ($rowRef->table) {
            SourceRoute::Post     => $this->postRepo,
            SourceRoute::Postmeta => $this->postmetaRepo,
            SourceRoute::Terms    => $this->termsRepo,
            SourceRoute::I18n     => $this->i18nRepo,
            SourceRoute::Html     => $this->htmlRepo,
        };
    }
}

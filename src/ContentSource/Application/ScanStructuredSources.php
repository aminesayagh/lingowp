<?php

namespace TransLangs\ContentSource\Application;

use TransLangs\ContentSource\Domain\SourceReader;
use TransLangs\ContentSource\Domain\SourceRoute;
use TransLangs\ContentSource\Domain\SourceString;
use TransLangs\TranslationMemory\Application\DiscoverSourceStrings;
use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;

/**
 * Orchestrates all structured-source readers, converts raw SourceStrings into
 * hashed TranslationSourceIdentity objects, and hands them to DiscoverSourceStrings
 * which fans them out to every active target language.
 *
 * Readers are paginated so memory stays bounded on large sites.
 * Re-running is safe: DiscoverSourceStrings uses INSERT IGNORE, so already-
 * discovered strings are silently skipped.
 */
class ScanStructuredSources
{
    private const BATCH_SIZE = 100;

    /** @var SourceReader[] */
    private array $readers;

    /** @param SourceReader[] $readers */
    public function __construct(
        array $readers,
        private readonly DiscoverSourceStrings $discovery,
        private readonly StringNormalizer $normalizer,
        private readonly StringHashGenerator $hasher
    ) {
        $this->readers = $readers;
    }

    public function addReader(SourceReader $reader): void
    {
        $this->readers[] = $reader;
    }

    /**
     * Run all available readers, or just the one matching $filterKey.
     */
    public function scan(?string $filterKey = null): ScanResult
    {
        $totalStrings  = 0;
        $totalInserted = 0;
        $byReader      = [];

        foreach ($this->readers as $reader) {
            if (!$reader->isAvailable()) {
                continue;
            }

            if ($filterKey !== null && $reader->key() !== $filterKey) {
                continue;
            }

            [$strings, $inserted] = $this->scanReader($reader);

            $totalStrings  += $strings;
            $totalInserted += $inserted;
            $byReader[$reader->key()] = ['strings' => $strings, 'inserted' => $inserted];
        }

        return new ScanResult($totalStrings, $totalInserted, $byReader);
    }

    /**
     * @return array{int, int} [stringsRead, rowsInserted]
     */
    private function scanReader(SourceReader $reader): array
    {
        $stringsRead  = 0;
        $rowsInserted = 0;
        $offset       = 0;

        do {
            $batch = $reader->read($offset, self::BATCH_SIZE);

            if ($batch === []) {
                break;
            }

            $identities    = $this->toIdentities($batch);
            $rowsInserted += $this->discovery->discover($identities);
            $count         = count($batch);
            $stringsRead  += $count;
            $offset       += $count;
        } while ($count === self::BATCH_SIZE);

        return [$stringsRead, $rowsInserted];
    }

    /**
     * Normalize each raw string, skip empties, compute route-specific hashes,
     * and build the identity objects the discovery service expects.
     *
     * @param  SourceString[]             $strings
     * @return TranslationSourceIdentity[]
     */
    private function toIdentities(array $strings): array
    {
        $identities = [];

        foreach ($strings as $string) {
            $normalized = $this->normalizer->normalize($string->text);

            if ($normalized === '') {
                continue;
            }

            $identities[] = $this->buildIdentity($string, $normalized);
        }

        return $identities;
    }

    private function buildIdentity(SourceString $string, string $normalized): TranslationSourceIdentity
    {
        $originalHash = $this->hasher->hashNormalized($normalized);

        return match ($string->routeTable) {
            SourceRoute::Post, SourceRoute::Terms => new TranslationSourceIdentity(
                routeTable:    $string->routeTable,
                parentId:      $string->parentId,
                field:         $string->field,
                metaKey:       null,
                metaKeyHash:   null,
                context:       null,
                contextHash:   null,
                source:        null,
                reference:     null,
                referenceHash: null,
                originalText:  $string->text,
                normalizedText: $normalized,
                originalHash:  $originalHash,
            ),
            SourceRoute::Postmeta => new TranslationSourceIdentity(
                routeTable:    SourceRoute::Postmeta,
                parentId:      $string->parentId,
                field:         null,
                metaKey:       $string->metaKey,
                metaKeyHash:   $this->hasher->hashMetaKey((string) $string->metaKey),
                context:       $string->context,
                contextHash:   $this->hasher->hashContext($string->context),
                source:        null,
                reference:     null,
                referenceHash: null,
                originalText:  $string->text,
                normalizedText: $normalized,
                originalHash:  $originalHash,
            ),
            SourceRoute::I18n => new TranslationSourceIdentity(
                routeTable:    SourceRoute::I18n,
                parentId:      0,
                field:         null,
                metaKey:       null,
                metaKeyHash:   null,
                context:       null,
                contextHash:   null,
                source:        $string->source,
                reference:     $string->reference,
                referenceHash: $this->hasher->hashReference((string) $string->reference),
                originalText:  $string->text,
                normalizedText: $normalized,
                originalHash:  $originalHash,
            ),
        };
    }
}

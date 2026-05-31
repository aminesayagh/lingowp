<?php

namespace TransLangs\ContentSource\Application;

use TransLangs\ContentSource\Domain\SourceReader;
use TransLangs\ContentSource\Domain\SourceString;
use TransLangs\TranslationMemory\Application\DiscoverSourceStrings;
use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;
use TransLangs\TranslationMemory\Domain\TranslationSourceIdentity;

/**
 * Orchestrates all structured-source readers in the prescribed order
 * (media → taxonomy → menu → post types → woocommerce), converts raw
 * SourceStrings into hashed TranslationSourceIdentity objects, and hands
 * them to DiscoverSourceStrings, which fans them out to every enabled
 * non-default language.
 *
 * Readers are paginated so memory stays bounded on large sites.
 * Re-running a scan is safe: DiscoverSourceStrings uses INSERT IGNORE,
 * so already-discovered strings are silently skipped.
 */
class ScanStructuredSources
{
    private const BATCH_SIZE = 100;

    /** @var SourceReader[] */
    private array $readers;

    /** @param SourceReader[] $readers Initial ordered list; more can be added via addReader(). */
    public function __construct(
        array $readers,
        private readonly DiscoverSourceStrings $discovery,
        private readonly StringNormalizer $normalizer,
        private readonly StringHashGenerator $hasher
    ) {
        $this->readers = $readers;
    }

    /**
     * Append a reader after construction — used to add CPT readers on 'init'
     * once all post types are registered.
     */
    public function addReader(SourceReader $reader): void
    {
        $this->readers[] = $reader;
    }

    /**
     * Run all available readers, or just the one whose key() matches $filterKey.
     *
     * Each reader is paginated in BATCH_SIZE chunks. Unavailable readers
     * (e.g. WooCommerce reader when WooCommerce is inactive) are silently skipped.
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
     * Page through one reader until it returns an empty batch, collecting
     * and discovering strings batch-by-batch.
     *
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
     * Normalize each raw string, skip empties, generate the hash, and build
     * the identity objects the discovery service expects.
     *
     * @param  SourceString[]            $strings
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

            $identities[] = TranslationSourceIdentity::source(
                $string->sourceId,
                $string->sourceType,
                $string->context,
                $string->text,
                $this->hasher->generate($normalized),
                $normalized
            );
        }

        return $identities;
    }
}

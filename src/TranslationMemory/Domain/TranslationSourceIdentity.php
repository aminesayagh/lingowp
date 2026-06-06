<?php

namespace TransLangs\TranslationMemory\Domain;

use TransLangs\ContentSource\Domain\SourceRoute;

/**
 * A SourceString after normalization and hashing — ready for DB insertion.
 *
 * Built by the discovery service from a SourceString. Holds all fields needed
 * to INSERT into the correct split table (one per active target language).
 * INSERT IGNORE on the table's unique key makes discovery idempotent.
 *
 * All *Hash fields are raw SHA-256 bytes (binary(32) in the DB).
 * Use bin2hex() only when building preload map keys or displaying to users.
 */
final class TranslationSourceIdentity
{
    /**
     * @param string      $originalHash   sha256(normalizedText), raw bytes.
     * @param string|null $metaKeyHash    sha256(metaKey), raw bytes. Postmeta only.
     * @param string|null $contextHash    sha256(context ?? ''), raw bytes. Never null for postmeta.
     * @param string|null $referenceHash  sha256(normalized reference), raw bytes. i18n only.
     */
    public function __construct(
        public readonly SourceRoute $routeTable,
        public readonly int         $parentId,
        public readonly ?string     $field,
        public readonly ?string     $metaKey,
        public readonly ?string     $metaKeyHash,
        public readonly ?string     $context,
        public readonly ?string     $contextHash,
        public readonly ?string     $source,
        public readonly ?string     $reference,
        public readonly ?string     $referenceHash,
        public readonly string      $originalText,
        public readonly string      $normalizedText,
        public readonly string      $originalHash,
    ) {}
}

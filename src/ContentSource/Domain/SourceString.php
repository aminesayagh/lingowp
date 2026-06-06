<?php

namespace TransLangs\ContentSource\Domain;

/**
 * One translatable string emitted by a reader — before normalization/hashing.
 *
 * Readers produce SourceStrings; the discovery service normalizes, hashes, and
 * routes them into TranslationSourceIdentity for DB insertion.
 */
final class SourceString
{
    /**
     * @param SourceRoute $routeTable Which translation table this string belongs to.
     * @param int         $parentId   post_id | term_taxonomy_id; 0 for i18n.
     * @param string|null $field      post: 'post_title'; terms: 'name'; null for postmeta/i18n.
     * @param string|null $metaKey    Postmeta only: e.g. '_wp_attachment_image_alt'.
     * @param string|null $context    Postmeta: nested path into meta value; null/'' for flat.
     * @param string|null $source     i18n only: 'plugin'|'theme'|'core'.
     * @param string|null $reference  i18n only: e.g. 'woocommerce/woocommerce.php'.
     * @param string      $text       Raw original text, un-normalized.
     */
    public function __construct(
        public readonly SourceRoute $routeTable,
        public readonly int         $parentId,
        public readonly ?string     $field,
        public readonly ?string     $metaKey,
        public readonly ?string     $context,
        public readonly ?string     $source,
        public readonly ?string     $reference,
        public readonly string      $text,
    ) {}
}
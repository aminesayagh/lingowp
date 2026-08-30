<?php

namespace LingoWP\Shared\Source\Type;
use LingoWP\Shared\Source\SourceRecord;

use InvalidArgumentException;

/**
 * A WooCommerce global product attribute's own label (e.g. "Size"), distinct
 * from its term values ("Large", "Red") which already go through the
 * ordinary taxonomy-term path. Identity is the attribute_id alone — this
 * table only exists on a site with WooCommerce active (see
 * TranslationMemorySchema::maybeInstallWooCommerceTables()) and its owner FK
 * points at WooCommerce's own wp_woocommerce_attribute_taxonomies table, not
 * a WordPress-core one.
 *
 * Hash-link kind (like post/term/option): the translation is keyed by
 * original_hash alone, so two attributes that happen to share a label share
 * one translation row.
 */
final class WooAttributeLabelSource extends SourceRecord
{
    private int    $attributeId;
    private string $text;

    public function __construct(int $attributeId, string $text)
    {
        $this->attributeId = $attributeId;
        $this->text        = $text;

        if ($attributeId <= 0) {
            throw new InvalidArgumentException('WooAttributeLabelSource: attribute id must be positive.');
        }
    }

    public function table(): string
    {
        return 'woo_attribute_label';
    }

    public function originalText(): string
    {
        return $this->text;
    }

    public function identity(): array
    {
        return [
            'attribute_id'  => $this->attributeId,
            'original_hash' => $this->originalHash(),
        ];
    }
}

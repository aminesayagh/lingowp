<?php

namespace LingoWP\Shared\Source\Type;
use LingoWP\Shared\Source\SourceRecord;

use InvalidArgumentException;

/**
 * A WooCommerce tax rate's own name (e.g. "VAT"), shown on the cart/checkout
 * tax line. Identity is the tax_rate_id alone — same conditional-existence
 * and hash-link shape as WooAttributeLabelSource; owner FK points at
 * WooCommerce's own wp_woocommerce_tax_rates table.
 */
final class WooTaxRateSource extends SourceRecord
{
    private int    $taxRateId;
    private string $text;

    public function __construct(int $taxRateId, string $text)
    {
        $this->taxRateId = $taxRateId;
        $this->text      = $text;

        if ($taxRateId <= 0) {
            throw new InvalidArgumentException('WooTaxRateSource: tax rate id must be positive.');
        }
    }

    public function table(): string
    {
        return 'woo_tax_rate';
    }

    public function originalText(): string
    {
        return $this->text;
    }

    public function identity(): array
    {
        return [
            'tax_rate_id'   => $this->taxRateId,
            'original_hash' => $this->originalHash(),
        ];
    }
}

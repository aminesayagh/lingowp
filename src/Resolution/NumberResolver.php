<?php

namespace LingoWP\Resolution;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Resolution\RequestContext;

final class NumberResolver
{
    use DeferredTranslationActivation;

    private array $formatters = [];

    private ResolveRequestLanguage $language;
    private RequestContext $context;

    public function __construct(
        ResolveRequestLanguage $language,
        RequestContext $context
    ) {
        $this->language = $language;
        $this->context  = $context;
    }

    public function register(): void
    {
        add_filter('number_format_i18n', [$this, 'reformat'], 10, 3);
        add_filter('formatted_woocommerce_price', [$this, 'reformat'], 10, 3);
    }

    public function reformat($value, $number, $decimals)
    {
        if (!is_string($value) || $value === '' || !is_numeric($number)) {
            return $value;
        }
        if (!$this->isActive() || !class_exists(\NumberFormatter::class)) {
            return $value;
        }

        try {
            $formatter = $this->formatterFor((int) $decimals);
            if ($formatter === null) {
                return $value;
            }

            $formatted = $formatter->format((float) $number);

            return $formatted === false ? $value : $formatted;
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function formatterFor(int $decimals): ?\NumberFormatter
    {
        $key = $this->lang . '|' . $decimals;
        if (array_key_exists($key, $this->formatters)) {
            return $this->formatters[$key];
        }

        try {
            $formatter = new \NumberFormatter($this->lang . '@numbers=latn', \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(\NumberFormatter::ROUNDING_MODE, \NumberFormatter::ROUND_HALFUP);
        } catch (\Throwable $e) {
            $formatter = null;
        }

        return $this->formatters[$key] = $formatter;
    }
}

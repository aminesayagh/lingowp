<?php

namespace LingoWP\Resolution;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Resolution\RequestContext;

final class DateResolver
{
    use DeferredTranslationActivation;

    private const TOKEN_PATTERNS = [
        'F' => 'MMMM',
        'M' => 'MMM',
        'l' => 'EEEE',
        'D' => 'EEE',
        'a' => 'a',
        'A' => 'a',
    ];

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
        add_filter('wp_date', [$this, 'reformat'], 10, 4);
    }

    public function reformat($value, $format, $timestamp, $timezone)
    {
        if (!is_string($value) || $value === '' || !is_int($timestamp) || !$timezone instanceof \DateTimeZone) {
            return $value;
        }
        if (!$this->isActive() || !class_exists(\IntlDateFormatter::class)) {
            return $value;
        }

        try {
            [$spliced, $placeholders] = $this->spliceTokens((string) $format);
            if ($placeholders === []) {
                return $value;
            }

            $datetime = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
            $result   = $datetime->format($spliced);

            foreach ($placeholders as $placeholder => $token) {
                $localized = $this->localizedToken($token, $datetime);
                if ($localized === null) {
                    return $value;
                }
                $result = str_replace($placeholder, $localized, $result);
            }

            return $result;
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function spliceTokens(string $format): array
    {
        $tokenPlaceholder = [];
        $placeholders     = [];
        $spliced          = '';
        $next             = 0xE000;
        $len              = strlen($format);

        for ($i = 0; $i < $len; $i++) {
            $char = $format[$i];
            if ($char === '\\' && $i + 1 < $len) {
                $spliced .= $char . $format[++$i];
                continue;
            }
            if (!isset(self::TOKEN_PATTERNS[$char])) {
                $spliced .= $char;
                continue;
            }
            if (!isset($tokenPlaceholder[$char])) {
                $placeholder                = mb_chr($next++);
                $tokenPlaceholder[$char]     = $placeholder;
                $placeholders[$placeholder]  = $char;
            }
            $spliced .= '\\' . $tokenPlaceholder[$char];
        }

        return [$spliced, $placeholders];
    }

    private function localizedToken(string $token, \DateTimeImmutable $datetime): ?string
    {
        $formatter = $this->formatterFor(self::TOKEN_PATTERNS[$token]);
        if ($formatter === null) {
            return null;
        }

        $out = $formatter->format($datetime);
        if ($out === false || $out === '') {
            return null;
        }

        switch ($token) {
            case 'a':
                return mb_strtolower($out);
            case 'A':
                return mb_strtoupper($out);
            default:
                return $out;
        }
    }

    private function formatterFor(string $pattern): ?\IntlDateFormatter
    {
        $key = $this->lang . '|' . $pattern;
        if (array_key_exists($key, $this->formatters)) {
            return $this->formatters[$key];
        }

        try {
            $formatter = new \IntlDateFormatter(
                $this->lang,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                function_exists('wp_timezone') ? wp_timezone() : null,
                \IntlDateFormatter::GREGORIAN,
                $pattern
            );
        } catch (\Throwable $e) {
            $formatter = null;
        }

        return $this->formatters[$key] = $formatter;
    }
}

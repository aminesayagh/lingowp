<?php

namespace TransLangs\Resolution;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Resolution\RequestContext;

/**
 * Localizes WordPress's own date/time output to the visitor's target
 * language on a translated request.
 *
 * Hooks the single lowest-level filter, `wp_date` (wp-includes/functions.php),
 * rather than the older template-tag filters (get_the_date, get_the_time,
 * get_the_modified_date, get_the_modified_time): those all route through
 * wp_date() internally, and so does date_i18n() (the pre-5.3 legacy
 * function) in every case except the trivial 'U'/'G' raw-timestamp format.
 * Critically, wp_date() is also what a block theme's core Post Date block
 * calls DIRECTLY (wp-includes/blocks/post-date.php) — the template-tag
 * filters never fire for it at all, which is what a block-theme site
 * surfaced: dates stayed in the source language on every translated page
 * because nothing was hooking the function block themes actually use.
 *
 * wp_date() already localizes month/weekday/am-pm names — but to the SITE's
 * own current locale, via $wp_locale's translated strings spliced into the
 * format before formatting. That locale never changes for a translated
 * request (this plugin never touches WordPress's own locale), so a
 * French-sourced site's dates stay French even on an English target page,
 * and vice versa. This resolver re-does the same splice, but into the
 * TARGET language via IntlDateFormatter instead of $wp_locale.
 *
 * Splices tokens into the FORMAT STRING (placeholder-then-format), mirroring
 * wp_date()'s own technique, rather than searching the already-formatted
 * $value for an assumed-English month/day name and replacing it there: the
 * incoming value is already localized to the SOURCE locale (not reliably
 * English), abbreviated names are literal prefixes of full ones ("Jan" in
 * "January"), and some languages' full names contain other tokens' values as
 * substrings (French "samedi"/German "Samstag" both contain "am") — any of
 * which would corrupt a same-string find/replace. The incoming $value's
 * content is never read at all — this recomputes from $format/$timestamp/
 * $timezone directly, all of which wp_date() has already fully resolved
 * (never null) by the time its filter fires.
 */
final class DateResolver
{
    /** PHP date() token => ICU pattern for the same field. */
    private const TOKEN_PATTERNS = [
        'F' => 'MMMM', // full month name
        'M' => 'MMM',  // abbreviated month name
        'l' => 'EEEE', // full weekday name
        'D' => 'EEE',  // abbreviated weekday name
        'a' => 'a',    // am/pm, lowercase
        'A' => 'a',    // am/pm, uppercase
    ];

    private ?bool $active = null;
    private bool $activating = false;
    private string $lang = '';

    /** @var array<string, \IntlDateFormatter|null> "lang|pattern" => formatter, memoized per request */
    private array $formatters = [];

    public function __construct(
        private ResolveRequestLanguage $language,
        private RequestContext $context
    ) {
    }

    public function register(): void
    {
        add_filter('wp_date', [$this, 'reformat'], 10, 4);
    }

    /**
     * @param mixed $value
     * @param mixed $format
     * @param mixed $timestamp
     * @param mixed $timezone
     * @return mixed
     */
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
                return $value; // nothing locale-dependent in this format
            }

            $datetime = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
            $result   = $datetime->format($spliced);

            foreach ($placeholders as $placeholder => $token) {
                $localized = $this->localizedToken($token, $datetime);
                if ($localized === null) {
                    return $value; // formatter failed — bail rather than emit a half-substituted string
                }
                $result = str_replace($placeholder, $localized, $result);
            }

            return $result;
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * Replace each locale-dependent token in $format with a Unicode Private
     * Use Area placeholder character, escaped so DateTimeImmutable::format()
     * emits it literally instead of trying to interpret it. Every other
     * character (numeric fields, existing literal text, the ordinal `S`
     * token, which has no ICU equivalent and is deliberately left as-is)
     * passes through untouched.
     *
     * @return array{0: string, 1: array<string, string>} [spliced format, placeholder => token]
     */
    private function spliceTokens(string $format): array
    {
        $tokenPlaceholder = [];
        $placeholders     = [];
        $spliced          = '';
        $next             = 0xE000; // Unicode Private Use Area
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

        return match ($token) {
            'a' => mb_strtolower($out),
            'A' => mb_strtoupper($out),
            default => $out,
        };
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
                // Pinned explicitly: some Arabic-region ICU locales (e.g.
                // ar_SA) default to the Hijri calendar, which would silently
                // produce a wrong calendar system, not just wrong language.
                \IntlDateFormatter::GREGORIAN,
                $pattern
            );
        } catch (\Throwable) {
            $formatter = null;
        }

        return $this->formatters[$key] = $formatter;
    }

    /**
     * Deferred, memoized verdict — copied from OptionResolver::isActive().
     * shouldTranslate()/isTranslatedRequest() read conditional query tags
     * (is_feed(), etc.) that don't exist before the `wp` action fires, so a
     * verdict computed any earlier would wrongly memoize "untranslated" for
     * the whole request.
     */
    private function isActive(): bool
    {
        if ($this->active !== null) {
            return $this->active;
        }
        if (!did_action('wp') || $this->activating) {
            return false;
        }

        $this->activating = true;
        try {
            $active = $this->context->shouldTranslate() && $this->language->isTranslatedRequest();
        } finally {
            $this->activating = false;
        }

        $this->active = $active;
        if ($active) {
            $this->lang = $this->language->resolve();
        }

        return $this->active;
    }
}

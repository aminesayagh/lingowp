<?php

namespace LingoWP\Shared\Text;

/**
 * Decides whether a discovered string is worth translating, applied AFTER the
 * normalizer. A gate, not a transformer: it never changes the stored text or its
 * hash (StringNormalizer owns the frozen contract) — it only answers keep/skip.
 *
 * Conservative by design: anything containing a real word is kept. A string is
 * skipped only when it demonstrably carries nothing to translate —
 *   - empty after normalization;
 *   - no letter in any script (numbers, digit-dates like 2024-01-01, currency,
 *     percentages, phone numbers, symbols/arrows, and LingoWP' own digit
 *     placeholder units like "{1}{2}{/2}{/1}");
 *   - the whole string is a bare URL, e-mail address, or filename.
 *
 * Anchored full-string matches (URL/email/filename contain no spaces), so prose
 * that merely *contains* a URL keeps its letters and is never dropped.
 */
final class TranslatableText
{
    private const URL_RE      = '#^(https?://|www\.)\S+$#';
    private const EMAIL_RE    = '/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/';
    private const FILENAME_RE = '/^\S+\.(jpe?g|png|gif|svg|webp|ico|pdf|docx?|xlsx?|pptx?|csv|txt|zip|rar|gz|mp[34]|wav|mov|webm|css|js|json|xml|html?|woff2?|ttf)$/';

    private function __construct() {}

    public static function isTranslatable(string $text): bool
    {
        $normalized = StringNormalizer::normalize($text);

        if ($normalized === '') {
            return false;
        }
        if (!preg_match('/\p{L}/u', $normalized)) {
            return false;
        }
        if (preg_match(self::URL_RE, $normalized)) {
            return false;
        }
        if (preg_match(self::EMAIL_RE, $normalized)) {
            return false;
        }
        if (preg_match(self::FILENAME_RE, $normalized)) {
            return false;
        }

        return true;
    }
}

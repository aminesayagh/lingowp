<?php

namespace LingoWP\Shared\Text;

final class TranslatableText
{
    private const URL_RE      = '#^(https?://|www\.)\S+$#';
    private const EMAIL_RE    = '/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/';
    private const FILENAME_RE = '/^\S+\.(jpe?g|png|gif|svg|webp|ico|pdf|docx?|xlsx?|pptx?|csv|txt|zip|rar|gz|mp[34]|wav|mov|webm|css|js|json|xml|html?|woff2?|ttf)$/';
    private const ISO_TIMESTAMP_RE = '/^\d{4}-\d{2}-\d{2}[t ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(z|[+-]\d{2}:?\d{2})?$/';

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
        if (preg_match(self::ISO_TIMESTAMP_RE, $normalized)) {
            return false;
        }

        return true;
    }
}

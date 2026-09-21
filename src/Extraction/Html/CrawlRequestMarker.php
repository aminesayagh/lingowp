<?php

namespace LingoWP\Extraction\Html;

final class CrawlRequestMarker
{
    private const QUERY_ARG = 'lingowp_crawl';

    private function __construct()
    {
    }

    public static function urlWithMarker(string $url): string
    {
        return add_query_arg(self::QUERY_ARG, self::token(), $url);
    }

    public static function isCrawlRequest(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this is an HMAC bearer token, not a nonce; hash_equals() is the verification.
        $given = isset($_GET[self::QUERY_ARG]) ? sanitize_text_field(wp_unslash($_GET[self::QUERY_ARG])) : '';

        return $given !== '' && hash_equals(self::token(), $given);
    }

    private static function token(): string
    {
        static $token = null;

        return $token ??= hash_hmac('sha256', 'lingowp_html_crawl', wp_salt('auth'));
    }
}

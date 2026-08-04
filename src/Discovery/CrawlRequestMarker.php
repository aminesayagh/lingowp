<?php

namespace TransLangs\Discovery;

/**
 * Marks and recognises the background crawler's own loopback requests, so the
 * crawler-only data-tl-skip filters (HtmlDiscoveryCrawl::register()) never
 * fire on real visitor or marking-mode traffic — only on the URLs the crawler
 * fetched itself.
 *
 * No stored secret: the token is derived from wp_salt('auth'), which WordPress
 * already keeps private per install, so there is nothing here for an admin to
 * configure or for a migration to seed.
 */
final class CrawlRequestMarker
{
    private const QUERY_ARG = 'translangs_crawl';

    private function __construct()
    {
    }

    /** Append the crawl marker to a URL, merging with any existing query string. */
    public static function urlWithMarker(string $url): string
    {
        return add_query_arg(self::QUERY_ARG, self::token(), $url);
    }

    /** True when the current request carries a valid crawl marker. */
    public static function isCrawlRequest(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this is an HMAC bearer token, not a nonce; hash_equals() is the verification.
        $given = isset($_GET[self::QUERY_ARG]) ? sanitize_text_field(wp_unslash($_GET[self::QUERY_ARG])) : '';

        return $given !== '' && hash_equals(self::token(), $given);
    }

    private static function token(): string
    {
        return hash_hmac('sha256', 'translangs_html_crawl', wp_salt('auth'));
    }
}

<?php

namespace TransLangs\PublicSite\Infrastructure;

use TransLangs\Language\Application\ResolveRequestLanguage;

/**
 * Rewrites internal links so a visitor browsing in a language stays in it.
 *
 * Given the active language and an href found in the page, rewrite() returns
 * the prefixed URL when the link is an internal, navigable page link, or null
 * when the link must be left untouched (external, anchor, mailto/tel, admin,
 * asset/file, already-prefixed, etc.).
 *
 * It only decides on single URLs; the parser hands it each href and applies the
 * result in the same parse pass the Renderer already runs. No separate parse.
 */
class LinkRewriter
{
    /**
     * Path prefixes that must never be rewritten (admin, includes, content,
     * REST, login, cron). Compared against the path, leading slash trimmed.
     *
     * @var string[]
     */
    private const SKIP_PATH_PREFIXES = [
        'wp-admin', 'wp-includes', 'wp-content', 'wp-json',
        'wp-login.php', 'wp-cron.php', 'xmlrpc.php',
    ];

    public function __construct(
        private ResolveRequestLanguage $resolver,
        private LocalizedUrlBuilder $prefixer
    ) {
    }

    /**
     * Return the rewritten href, or null to leave it unchanged.
     */
    public function rewrite(string $href, string $targetLang): ?string
    {
        $href = trim($href);

        if ($href === '') {
            return null;
        }

        // Non-navigational schemes and in-page anchors: never touch.
        if ($this->isNonNavigational($href)) {
            return null;
        }

        // Protocol-relative or absolute URL: only rewrite if same host.
        $parts = wp_parse_url($href);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['host']) && !$this->isInternalHost($parts['host'])) {
            return null; // external link
        }

        $path = isset($parts['path']) ? $parts['path'] : '/';

        if ($this->isSkippablePath($path)) {
            return null;
        }

        // Already in the target language: nothing to do.
        if ($this->prefixer->detectPrefix($path) === $targetLang) {
            return null;
        }

        $newPath = $this->prefixer->addPrefix($path, $targetLang);

        if ($newPath === $path) {
            return null; // no change (e.g. target is the non-prefixed default)
        }

        return $this->rebuild($parts, $newPath, $href);
    }

    // ------------------------------------------------------------------

    /**
     * Schemes/forms we never rewrite: anchors, mailto, tel, javascript, data.
     */
    private function isNonNavigational(string $href): bool
    {
        if ($href[0] === '#') {
            return true;
        }

        return (bool) preg_match('#^(mailto:|tel:|javascript:|data:|sms:|ftp:)#i', $href);
    }

    private function isInternalHost(string $host): bool
    {
        $home = wp_parse_url(home_url('/'), PHP_URL_HOST);

        return strtolower($host) === strtolower((string) $home);
    }

    private function isSkippablePath(string $path): bool
    {
        $trimmed = ltrim($path, '/');

        foreach (self::SKIP_PATH_PREFIXES as $prefix) {
            if ($trimmed === $prefix || strpos($trimmed, $prefix . '/') === 0) {
                return true;
            }
        }

        // Skip direct file links (anything with a file extension), e.g. .pdf,
        // .jpg, .zip. Pages are extensionless or end in a slash.
        $last = basename($path);
        if ($last !== '' && strpos($last, '.') !== false) {
            $ext = strtolower((string) pathinfo($last, PATHINFO_EXTENSION));
            // Allow .html/.htm/.php pages; block obvious assets/downloads.
            if ($ext !== '' && !in_array($ext, ['html', 'htm', 'php'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reassemble a URL from its parts with the new path. Keeps query/fragment.
     * For input that was a bare path, returns a bare path (no host injected).
     *
     * @param array<string, mixed> $parts
     */
    private function rebuild(array $parts, string $newPath, string $original): string
    {
        $query    = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        // Original was a relative path (no scheme/host): keep it relative.
        if (!isset($parts['host'])) {
            return $newPath . $query . $fragment;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '//';
        $host   = $parts['host'];
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . $host . $port . $newPath . $query . $fragment;
    }
}

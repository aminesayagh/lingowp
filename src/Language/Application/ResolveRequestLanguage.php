<?php

namespace TransLangs\Language\Application;

use TransLangs\Language\Domain\LanguageRegistry;

/**
 * Decides the active language for the current request.
 *
 * Priority chain (highest wins):
 *   1. URL language prefix          /fr/about
 *   2. Unprefixed default URL       /about, when default language is unprefixed
 *   3. Cookie preference            preferred_lang=fr   (if enabled in settings)
 *   4. Browser Accept-Language      (only if browser detection enabled)
 *   5. Default site language        from get_locale()
 *
 * Hard rule: the URL always wins. /fr/about renders French even if the
 * cookie says preferred_lang=es. When the default language is not prefixed,
 * /about is also an explicit URL decision: it renders the default language.
 * The cookie is only a fallback preference, and only when the admin enabled it.
 *
 * The result is cached per request so repeated calls do not re-run the chain.
 */
class ResolveRequestLanguage
{
    public const COOKIE_NAME = 'preferred_lang';

    private ?string $resolved = null;

    public function __construct(private LanguageRegistry $registry) {}

    /**
     * Resolve and memoize the active language for this request.
     */
    public function resolve(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $lang = $this->resolveFromUrl()
            ?? $this->resolveFromUnprefixedDefaultUrl()
            ?? $this->resolveFromCookie()
            ?? $this->resolveFromBrowser()
            ?? $this->getDefaultLanguage();

        $this->resolved = $lang;
        $this->syncPreferenceCookie($lang);

        return $lang;
    }

    // ------------------------------------------------------------------
    // Language registry accessors
    // ------------------------------------------------------------------

    public function getDefaultLanguage(): string
    {
        return $this->registry->getDefaultLanguage();
    }

    /** @return string[] */
    public function getEnabledLanguages(): array
    {
        return $this->registry->getEnabledLanguages();
    }

    public function isEnabledLanguage(string $lang): bool
    {
        return $this->registry->isEnabledLanguage($lang);
    }

    public function isTranslatedRequest(): bool
    {
        return $this->resolve() !== $this->getDefaultLanguage();
    }

    private function cookieEnabled(): bool
    {
        return (bool) get_option('translangs_cookie_preference', true);
    }

    private function browserDetectionEnabled(): bool
    {
        return (bool) get_option('translangs_browser_detection', false);
    }

    // ------------------------------------------------------------------
    // Resolution steps (each returns a valid enabled code, or null)
    // ------------------------------------------------------------------

    /**
     * 1. URL language prefix, populated by the rewrite rule into a query var.
     */
    private function resolveFromUrl(): ?string
    {
        $lang = get_query_var('translangs_lang');

        return $this->validate(is_string($lang) ? $lang : '');
    }

    /**
     * 2. When the default language has no URL prefix, an unprefixed public URL
     *    is the canonical default-language URL and must beat the preference
     *    cookie.
     */
    private function resolveFromUnprefixedDefaultUrl(): ?string
    {
        if ((bool) get_option('translangs_prefix_default_language', false)) {
            return null;
        }

        if ($this->isNonPublicRequest()) {
            return null;
        }

        $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        if ($path === '') {
            return $this->getDefaultLanguage();
        }

        $first = strtolower(explode('/', $path)[0]);
        if ($this->isEnabledLanguage($first)) {
            return null;
        }

        return $this->getDefaultLanguage();
    }

    /**
     * 4. Cookie preference. Only used as a fallback when the URL did not
     *    already identify a language.
     */
    private function resolveFromCookie(): ?string
    {
        if (!$this->cookieEnabled() || empty($_COOKIE[self::COOKIE_NAME])) {
            return null;
        }

        return $this->validate(sanitize_key(wp_unslash($_COOKIE[self::COOKIE_NAME])));
    }

    /**
     * 5. Browser Accept-Language. Optional and off by default. Picks the first
     *    enabled language the browser asks for, by quality order.
     */
    private function resolveFromBrowser(): ?string
    {
        if (!$this->browserDetectionEnabled() || empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            return null;
        }

        foreach ($this->parseAcceptLanguage((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']) as $code) {
            $valid = $this->validate($code);
            if ($valid !== null) {
                return $valid;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Return the code only if it is non-empty and an enabled language.
     */
    private function validate(string $lang): ?string
    {
        $lang = sanitize_key($lang);

        return ($lang !== '' && $this->isEnabledLanguage($lang)) ? $lang : null;
    }

    private function isNonPublicRequest(): bool
    {
        if (function_exists('is_admin') && is_admin()) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return true;
        }

        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }

        $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');

        return $path === 'wp-login.php';
    }

    private function syncPreferenceCookie(string $lang): void
    {
        if (!$this->cookieEnabled() || headers_sent()) {
            return;
        }

        $current = isset($_COOKIE[self::COOKIE_NAME])
            ? sanitize_key(wp_unslash($_COOKIE[self::COOKIE_NAME]))
            : '';

        if ($current === $lang) {
            return;
        }

        setcookie(self::COOKIE_NAME, $lang, [
            'expires'  => time() + 31536000,
            'path'     => '/',
            'secure'   => function_exists('is_ssl') && is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[self::COOKIE_NAME] = $lang;
    }

    /**
     * Parse an Accept-Language header into base codes, ordered by q-weight.
     * "fr-CA,fr;q=0.9,en;q=0.8" => ['fr', 'fr', 'en']
     *
     * @return string[]
     */
    private function parseAcceptLanguage(string $header): array
    {
        $ranked = [];

        foreach (explode(',', $header) as $part) {
            $bits = explode(';q=', trim($part));
            $tag  = strtolower(trim($bits[0]));
            $q    = isset($bits[1]) ? (float) $bits[1] : 1.0;

            if ($tag === '') {
                continue;
            }

            // Reduce "fr-CA" to "fr".
            $base     = explode('-', $tag)[0];
            $ranked[] = ['code' => $base, 'q' => $q];
        }

        usort($ranked, static fn($a, $b) => $b['q'] <=> $a['q']);

        return array_column($ranked, 'code');
    }
}

<?php

namespace TransLangs\Language\Application;

use TransLangs\Language\Domain\LanguageRegistry;

/**
 * Decides the active language for the current request.
 *
 * Priority chain (highest wins):
 *   1. URL language prefix          /fr/about
 *   2. Admin/preview query param    ?translangs_preview=fr
 *   3. Cookie preference            preferred_lang=fr   (if enabled in settings)
 *   4. Browser Accept-Language      (only if browser detection enabled)
 *   5. Default site language        from get_locale()
 *
 * Hard rule: the URL always wins. /fr/about renders French even if the
 * cookie says preferred_lang=es. The cookie is only a preference for
 * unprefixed URLs, and only when the admin has enabled it.
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
            ?? $this->resolveFromPreview()
            ?? $this->resolveFromCookie()
            ?? $this->resolveFromBrowser()
            ?? $this->getDefaultLanguage();

        return $this->resolved = $lang;
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
     * 2. Explicit preview parameter, used by the visual editor iframe.
     *    Only honored for users who can edit, so it cannot be abused publicly.
     */
    private function resolveFromPreview(): ?string
    {
        if (empty($_GET['translangs_preview'])) {
            return null;
        }

        if (!function_exists('current_user_can') || !current_user_can('edit_posts')) {
            return null;
        }

        return $this->validate(sanitize_key(wp_unslash($_GET['translangs_preview'])));
    }

    /**
     * 3. Cookie preference. Only consulted for unprefixed URLs and only when
     *    the admin enabled it. Never overrides a URL prefix.
     */
    private function resolveFromCookie(): ?string
    {
        if (!$this->cookieEnabled() || empty($_COOKIE[self::COOKIE_NAME])) {
            return null;
        }

        return $this->validate(sanitize_key(wp_unslash($_COOKIE[self::COOKIE_NAME])));
    }

    /**
     * 4. Browser Accept-Language. Optional and off by default. Picks the first
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
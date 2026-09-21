<?php

namespace LingoWP\Language\Application;

use LingoWP\Language\Domain\LanguageRegistry;
use LingoWP\Language\Domain\LocaleNormalizer;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;

class ResolveRequestLanguage
{
    public const COOKIE_NAME = 'preferred_lang';

    private ?string $resolved = null;

    private LanguageRegistry $registry;

    public function __construct(LanguageRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function resolve(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $lang = $this->resolveFromUrl()
            ?? $this->resolveFromPreview()
            ?? $this->resolveFromUnprefixedDefaultUrl()
            ?? $this->resolveFromCookie()
            ?? $this->resolveFromBrowser()
            ?? $this->getDefaultLanguage();

        $this->resolved = $lang;
        $this->syncPreferenceCookie($lang);

        return $lang;
    }

    public function getDefaultLanguage(): string
    {
        return $this->registry->getDefaultLanguage();
    }

    public function getEnabledLanguages(): array
    {
        return $this->registry->getEnabledLanguages();
    }

    public function isEnabledLanguage(string $lang): bool
    {
        return $this->registry->isEnabledLanguage($lang);
    }

    public function slugForLanguage(string $lang): string
    {
        return $this->registry->slugForLanguage($lang);
    }

    public function languageForSlug(string $slug): ?string
    {
        return $this->registry->languageForSlug($slug);
    }

    public function isTranslatedRequest(): bool
    {
        return $this->resolve() !== $this->getDefaultLanguage();
    }

    private function cookieEnabled(): bool
    {
        return (bool) get_option('lingowp_cookie_preference', true);
    }

    private function browserDetectionEnabled(): bool
    {
        return (bool) get_option('lingowp_browser_detection', false);
    }

    private function resolveFromUrl(): ?string
    {
        $lang = get_query_var('lingowp_lang');

        return $this->validate(is_string($lang) ? $lang : '');
    }

    private function resolveFromPreview(): ?string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only language preview, gated on edit_posts; it changes no state, so a nonce would only break shareable preview URLs.
        if (empty($_GET['lingowp_preview'])) {
            return null;
        }

        if (!function_exists('current_user_can') || !current_user_can('edit_posts')) {
            return null;
        }

        return $this->validatePreviewable(sanitize_text_field(wp_unslash($_GET['lingowp_preview'])));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    private function resolveFromUnprefixedDefaultUrl(): ?string
    {
        if ((bool) get_option('lingowp_prefix_default_language', false)) {
            return null;
        }

        if ($this->isNonPublicRequest()) {
            return null;
        }

        $path = trim((string) parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')), PHP_URL_PATH), '/');
        if ($path === '') {
            return $this->getDefaultLanguage();
        }

        $first = rawurldecode(explode('/', $path)[0]);
        if ($this->languageForSlug($first) !== null) {
            return null;
        }

        return $this->getDefaultLanguage();
    }

    private function resolveFromCookie(): ?string
    {
        if (!$this->cookieEnabled() || empty($_COOKIE[self::COOKIE_NAME])) {
            return null;
        }

        return $this->validate(sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME])));
    }

    private function resolveFromBrowser(): ?string
    {
        if (!$this->browserDetectionEnabled() || empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            return null;
        }

        $accept = sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE']));

        foreach ($this->parseAcceptLanguage($accept) as $locale) {
            $valid = $this->validate($locale);
            if ($valid !== null) {
                return $valid;
            }

            $language = LocaleNormalizer::language($locale);
            foreach ($this->getEnabledLanguages() as $enabled) {
                if (LocaleNormalizer::language($enabled) === $language) {
                    return $enabled;
                }
            }
        }

        return null;
    }

    private function validate(string $lang): ?string
    {
        $lang = LocaleNormalizer::normalize($lang);

        return ($lang !== '' && $this->isEnabledLanguage($lang)) ? $lang : null;
    }

    private function validatePreviewable(string $lang): ?string
    {
        $lang = LocaleNormalizer::normalize($lang);
        if ($lang === '') {
            return null;
        }

        return $this->isEnabledLanguage($lang)
            || in_array($lang, $this->registry->existingLanguages(), true)
            || (new LanguageMetadataStore())->has($lang)
            ? $lang
            : null;
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

        $path = trim((string) parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')), PHP_URL_PATH), '/');

        return $path === 'wp-login.php';
    }

    private function syncPreferenceCookie(string $lang): void
    {
        if (!$this->cookieEnabled() || headers_sent()) {
            return;
        }

        $current = isset($_COOKIE[self::COOKIE_NAME])
            ? LocaleNormalizer::normalize(sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME])))
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

    private function parseAcceptLanguage(string $header): array
    {
        $ranked = [];

        foreach (explode(',', $header) as $part) {
            $bits = explode(';q=', trim($part));
            $tag  = LocaleNormalizer::normalize(trim($bits[0]));
            $q    = isset($bits[1]) ? (float) $bits[1] : 1.0;

            if ($tag === '') {
                continue;
            }

            $ranked[] = ['code' => $tag, 'q' => $q];
        }

        usort($ranked, static fn($a, $b) => $b['q'] <=> $a['q']);

        return array_column($ranked, 'code');
    }
}

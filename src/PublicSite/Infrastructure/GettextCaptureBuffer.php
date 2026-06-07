<?php

namespace TransLangs\PublicSite\Infrastructure;

use TransLangs\TranslationMemory\Domain\StringHashGenerator;
use TransLangs\TranslationMemory\Domain\StringNormalizer;

final class GettextCaptureBuffer
{
    private bool $active = false;
    private bool $paused = false;
    private string $requestArea = 'frontend';

    /** @var array<string, array{original_text: string, normalized_text: string, original_hash_hex: string, domain: string, hook: string}> */
    private array $candidates = [];

    public function start(): void
    {
        if ($this->active) {
            return;
        }

        $requestArea = $this->detectRequestArea();
        $this->requestArea = $requestArea;

        if (!$this->shouldCaptureRequest($requestArea)) {
            return;
        }

        $this->active = true;
        $this->candidates = [];

        add_filter('gettext', [$this, 'captureGettext'], 10, 3);
        add_filter('gettext_with_context', [$this, 'captureGettextWithContext'], 10, 4);
        add_filter('ngettext', [$this, 'captureNgettext'], 10, 5);

        // The logged-in admin bar renders at wp_footer (priority 1000) and calls
        // __('Howdy'), __('Edit'), etc. Those are WordPress chrome, not site
        // content, so we pause capture around its render window while still
        // letting the bar display for the logged-in user.
        add_action('wp_footer', [$this, 'pause'], 999);
        add_action('wp_footer', [$this, 'resume'], 1001);
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    public function capturedRequestArea(): string
    {
        return $this->requestArea;
    }

    /**
     * @return array<int, array{original_text: string, normalized_text: string, original_hash_hex: string, domain: string, hook: string}>
     */
    public function stop(): array
    {
        if (!$this->active) {
            return [];
        }

        remove_filter('gettext', [$this, 'captureGettext'], 10);
        remove_filter('gettext_with_context', [$this, 'captureGettextWithContext'], 10);
        remove_filter('ngettext', [$this, 'captureNgettext'], 10);

        $this->active = false;
        $candidates = array_values($this->candidates);
        $this->candidates = [];

        return $candidates;
    }

    public function captureGettext(string $translation, string $text, string $domain): string
    {
        $this->capture($text, $domain, 'gettext');

        return $translation;
    }

    public function captureGettextWithContext(
        string $translation,
        string $text,
        string $context,
        string $domain
    ): string {
        $this->capture($text, $domain, 'gettext_with_context');

        return $translation;
    }

    public function captureNgettext(
        string $translation,
        string $single,
        string $plural,
        int $number,
        string $domain
    ): string {
        $this->capture($number === 1 ? $single : $plural, $domain, 'ngettext');

        return $translation;
    }

    private function capture(string $text, string $domain, string $hook): void
    {
        if (!$this->active || $this->paused) {
            return;
        }

        $normalized = StringNormalizer::normalize($text);
        if ($normalized === '') {
            return;
        }

        $domain = $this->domain($domain);
        $hashHex = bin2hex(StringHashGenerator::hashNormalized($normalized));

        $this->candidates[$domain . ':' . $hashHex] = [
            'original_text'      => $text,
            'normalized_text'    => $normalized,
            'original_hash_hex'  => $hashHex,
            'domain'             => $domain,
            'hook'               => $hook,
        ];
    }

    private function shouldCaptureRequest(string $requestArea): bool
    {
        return in_array($requestArea, ['frontend', 'ajax_frontend'], true);
    }

    private function detectRequestArea(): string
    {
        if (defined('WP_CLI') && constant('WP_CLI')) {
            return 'cli';
        }

        if (defined('DOING_CRON') && constant('DOING_CRON')) {
            return 'cron';
        }

        if (defined('REST_REQUEST') && constant('REST_REQUEST')) {
            return 'rest';
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            $referer = function_exists('wp_get_referer') ? wp_get_referer() : false;

            if ($referer && function_exists('admin_url') && !str_starts_with($referer, admin_url())) {
                return 'ajax_frontend';
            }

            return 'ajax_admin';
        }

        if (function_exists('is_admin') && is_admin()) {
            return 'admin';
        }

        if (!empty($_SERVER['REQUEST_URI']) && str_contains((string) $_SERVER['REQUEST_URI'], 'wp-login.php')) {
            return 'login';
        }

        return 'frontend';
    }

    private function domain(string $domain): string
    {
        $domain = trim($domain);

        return $domain !== '' ? substr($domain, 0, 191) : 'default';
    }
}

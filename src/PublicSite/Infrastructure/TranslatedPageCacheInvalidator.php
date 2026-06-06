<?php

namespace TransLangs\PublicSite\Infrastructure;

use TransLangs\Language\Domain\LanguageRegistry;

final class TranslatedPageCacheInvalidator
{
    public function __construct(
        private TranslatedPageCache $cache,
        private LanguageRegistry $languageRegistry,
        private LocalizedUrlBuilder $urlBuilder
    ) {}

    public function register(): void
    {
        add_action('save_post', [$this, 'invalidatePost'], 10, 3);
        add_action('after_switch_theme', [$this, 'invalidateAll'], 10, 0);
    }

    public function invalidatePost(int $postId, mixed $post = null, bool $update = false): void
    {
        unset($post, $update);

        if ($postId <= 0) {
            return;
        }

        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($postId)) {
            return;
        }

        $revisionParent = function_exists('wp_is_post_revision') ? (int) wp_is_post_revision($postId) : 0;
        if ($revisionParent > 0) {
            $postId = $revisionParent;
        }

        if ($this->postTypeInvalidatesAll($postId)) {
            $this->invalidateAll();
            return;
        }

        $this->invalidatePostId($postId);
    }

    public function invalidatePostId(int $postId): void
    {
        if ($postId <= 0 || !function_exists('get_permalink')) {
            return;
        }

        $permalink = get_permalink($postId);
        if (!is_string($permalink) || $permalink === '') {
            return;
        }

        $this->invalidatePermalink($permalink);
    }

    public function invalidatePermalink(string $permalink): void
    {
        $targetLanguages = $this->languageRegistry->getTargetLanguages();
        if ($targetLanguages === []) {
            return;
        }

        $urlsByLanguage = [];
        foreach ($targetLanguages as $targetLang) {
            $urlsByLanguage[$targetLang] = $this->localizedUrl($permalink, $targetLang);
        }

        foreach ($urlsByLanguage as $targetLang => $url) {
            $this->cache->delete($url, $targetLang);
        }

        foreach (array_values(array_unique($urlsByLanguage)) as $url) {
            $this->cache->bumpUrlVersion($url);
        }
    }

    public function invalidateTranslations(): void
    {
        $this->invalidateAll();
    }

    public function invalidateLanguageSet(): void
    {
        $this->invalidateAll();
    }

    public function invalidateAll(): void
    {
        $this->cache->bumpVersion();
    }

    private function localizedUrl(string $url, string $targetLang): string
    {
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $path;
        $path = $this->urlBuilder->addPrefix($path, $targetLang);

        $suffix = '';
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $suffix .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && is_string($parts['fragment']) && $parts['fragment'] !== '') {
            $suffix .= '#' . $parts['fragment'];
        }

        return function_exists('home_url') ? (string) home_url($path . $suffix) : $path . $suffix;
    }

    private function postTypeInvalidatesAll(int $postId): bool
    {
        if (!function_exists('get_post_type')) {
            return false;
        }

        $postType = get_post_type($postId);
        if (!is_string($postType)) {
            return false;
        }

        return in_array($postType, [
            'attachment',
            'nav_menu_item',
            'wp_navigation',
            'wp_block',
            'wp_template_part',
            'wp_template',
        ], true);
    }
}

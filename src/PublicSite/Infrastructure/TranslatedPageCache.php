<?php

namespace TransLangs\PublicSite\Infrastructure;

final class TranslatedPageCache
{
    private const VERSION_OPTION            = 'translangs_content_version';
    private const URL_VERSION_OPTION_PREFIX = 'translangs_content_version_url_';
    private const KEY_PREFIX                = 'translangs_page_';
    private const DEFAULT_TTL               = 43200;

    public function get(string $url, string $targetLang): ?string
    {
        $html = get_transient($this->key($url, $targetLang));

        return is_string($html) ? $html : null;
    }

    public function set(string $url, string $targetLang, string $html): void
    {
        set_transient($this->key($url, $targetLang), $html, self::DEFAULT_TTL);
    }

    public function delete(string $url, string $targetLang): void
    {
        delete_transient($this->key($url, $targetLang));
    }

    public function bumpVersion(): void
    {
        update_option(self::VERSION_OPTION, $this->newVersion());
    }

    public function bumpUrlVersion(string $url): void
    {
        update_option($this->urlVersionOption($url), $this->newVersion(), false);
    }

    private function key(string $url, string $targetLang): string
    {
        return self::KEY_PREFIX . hash('sha256', $targetLang . '|' . $this->version($url) . '|' . $url);
    }

    private function version(string $url): string
    {
        $version = get_option(self::VERSION_OPTION, '1');
        $global  = is_string($version) && $version !== '' ? $version : '1';

        return $global . '|' . $this->urlVersion($url);
    }

    private function urlVersion(string $url): string
    {
        $version = get_option($this->urlVersionOption($url), '1');

        return is_string($version) && $version !== '' ? $version : '1';
    }

    private function urlVersionOption(string $url): string
    {
        return self::URL_VERSION_OPTION_PREFIX . hash('sha256', $url);
    }

    private function newVersion(): string
    {
        return sprintf('%.6F', microtime(true));
    }
}

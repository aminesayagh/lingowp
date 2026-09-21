<?php

namespace LingoWP\LocalizationRouting;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Domain\FlagResolver;
use LingoWP\Language\Domain\LocaleNormalizer;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Language\Infrastructure\SupportedLanguages;

class LanguageLinks
{
    private static ?self $primed = null;

    private array $baseCache = [];

    private ResolveRequestLanguage $resolver;
    private LocalizedUrlBuilder $prefixer;
    private SupportedLanguages $languages;

    public function __construct(
        ResolveRequestLanguage $resolver,
        LocalizedUrlBuilder $prefixer,
        SupportedLanguages $languages
    ) {
        $this->resolver  = $resolver;
        $this->prefixer  = $prefixer;
        $this->languages = $languages;
    }

    public static function instance(): self
    {
        if (self::$primed instanceof self) {
            return self::$primed;
        }

        $resolver = new ResolveRequestLanguage(new OptionLanguageRegistry());

        return new self(
            $resolver,
            new LocalizedUrlBuilder($resolver),
            new SupportedLanguages(new LanguageMetadataStore())
        );
    }

    public static function prime(self $instance): void
    {
        self::$primed = $instance;
    }

    public function links(array $args = []): array
    {
        $rows = $this->baseRows();
        if ($rows === []) {
            return $rows;
        }

        $include = $args['include'] ?? null;
        $order   = $args['order'] ?? 'settings';

        if (is_array($include) && $include !== []) {
            $rows = $this->only($rows, $include);
        } elseif (is_array($order)) {
            $rows = $this->only($rows, $order);
        } elseif ($order === 'alpha') {
            usort($rows, static fn(array $a, array $b): int => strcasecmp((string) $a['native_name'], (string) $b['native_name']));
        }

        if (!empty($args['hide_current'])) {
            $rows = array_filter($rows, static fn(array $r): bool => empty($r['current']));
        }

        $rows = array_values($rows);

        return array_values((array) apply_filters('lingowp_language_links', $rows, $args));
    }

    private function baseRows(): array
    {
        $active = $this->resolver->resolve();
        $uri    = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $key    = md5($uri . '|' . $active);

        if (isset($this->baseCache[$key])) {
            return $this->baseCache[$key];
        }

        $enabled = $this->resolver->getEnabledLanguages();
        if (count($enabled) < 2) {
            return $this->baseCache[$key] = [];
        }

        $default = $this->resolver->getDefaultLanguage();

        $ordered = [];
        foreach ($enabled as $code) {
            if ($code === $default) {
                array_unshift($ordered, $code);
            } else {
                $ordered[] = $code;
            }
        }

        $rawPath = $this->currentPath();
        $slash   = substr($rawPath, -1) === '/';
        $path    = $this->prefixer->stripPrefix($rawPath);
        $query   = $this->currentQuery();
        $origin  = $this->origin();

        $rows = [];
        foreach ($ordered as $code) {
            $localized = $this->prefixer->addPrefix($path, $code);

            if ($slash && substr($localized, -1) !== '/') {
                $localized .= '/';
            }

            $rows[] = [
                'code'         => $code,
                'slug'         => $this->resolver->slugForLanguage($code),
                'url'          => $origin . $localized . $query,
                'native_name'  => $this->languages->nativeLabel($code),
                'english_name' => $this->languages->label($code),
                'dir'          => $this->languages->direction($code),
                'region'       => LocaleNormalizer::region($code),
                'flag_url'     => FlagResolver::url($code) ?? '',
                'is_source'    => $code === $default,
                'current'      => $code === $active,
            ];
        }

        return $this->baseCache[$key] = $rows;
    }

    private function only(array $rows, array $codes): array
    {
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[$row['code']] = $row;
        }

        $out = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            if (isset($byCode[$code])) {
                $out[] = $byCode[$code];
            }
        }

        return $out;
    }

    private function currentPath(): string
    {
        $uri  = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);

        return $path === '' ? '/' : $path;
    }

    private function currentQuery(): string
    {
        $uri   = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $query = (string) wp_parse_url($uri, PHP_URL_QUERY);

        return $query !== '' ? '?' . $query : '';
    }

    private function origin(): string
    {
        $parts = wp_parse_url(home_url('/'));
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = $parts['scheme'] ?? ((function_exists('is_ssl') && is_ssl()) ? 'https' : 'http');
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $parts['host'] . $port;
    }
}

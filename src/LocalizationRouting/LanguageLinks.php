<?php

namespace LingoWP\LocalizationRouting;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Domain\FlagResolver;
use LingoWP\Language\Domain\LocaleNormalizer;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Language\Infrastructure\SupportedLanguages;

/**
 * Turns "which languages does this site have" + "what page am I on" into a list
 * of {code, slug, url, names, dir, region, flag_url, is_source, current} rows.
 *
 * Every switcher surface reads from here — the bundled renderer, the shortcode,
 * and any theme or plugin calling lingowp_get_language_links() — so they can
 * never disagree about a page's URL in a given language. Each row `url` is the
 * CURRENT request re-expressed with that language's prefix, built by the same
 * LocalizedUrlBuilder that drives hreflang and link rewriting.
 *
 * No database: reads the language registry (an option) and $_SERVER only. The
 * unfiltered row set is memoized per request, so calling this many times on a
 * page costs one build.
 */
class LanguageLinks
{
    private static ?self $primed = null;

    /** @var array<string, array<int, array<string, mixed>>> */
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

    /**
     * The instance primed by Plugin::bootLocalizationRouting(). Falls back to a
     * self-constructed default so a theme calling lingowp_get_language_links()
     * before boot finishes — or a unit test — still works.
     */
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

    /**
     * @param array<string, mixed> $args {
     *   @type bool            $hide_current  Drop the active language.
     *   @type string[]        $include       Keep only these locales, in this order.
     *   @type string|string[] $order         'settings' (default), 'alpha', or an explicit locale list.
     * }
     * @return array<int, array{code:string,slug:string,url:string,native_name:string,english_name:string,dir:string,region:string,flag_url:string,is_source:bool,current:bool}>
     */
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

        /**
         * Reorder, relabel or drop languages once, for every switcher surface.
         *
         * @param array<int, array<string, mixed>> $rows
         * @param array<string, mixed>             $args
         */
        return array_values((array) apply_filters('lingowp_language_links', $rows, $args));
    }

    /**
     * The full row set (all enabled languages, source first, no args applied),
     * memoized per request URI + active language.
     *
     * @return array<int, array<string, mixed>>
     */
    private function baseRows(): array
    {
        $active = $this->resolver->resolve();
        // esc_url_raw, NOT sanitize_text_field: that one strips every
        // percent-encoded octet in a loop (see wp-includes/formatting.php), so
        // a non-Latin slug like /ar/%D8%B9%D9%86/ collapsed to /ar/-/ and every
        // switcher href, hreflang target and redirect built from it pointed at
        // the site root. esc_url_raw keeps %XX and still satisfies the input
        // sanitization sniff.
        $uri    = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));

        // The slug-map generation is part of the key: these rows embed
        // translated slugs, so a translation saved mid-request (or a batch
        // process that saves and re-renders) would otherwise keep serving URLs
        // built before the change. SlugMap's own memo is already generation-
        // keyed; this cache sits in front of it and has to agree.
        $key    = md5($uri . '|' . $active . '|' . SlugMap::version());

        if (isset($this->baseCache[$key])) {
            return $this->baseCache[$key];
        }

        $enabled = $this->resolver->getEnabledLanguages();
        if (count($enabled) < 2) {
            return $this->baseCache[$key] = [];
        }

        $default = $this->resolver->getDefaultLanguage();

        // Source first, then the rest in registry order. getEnabledLanguages()
        // already returns [default, ...targets], but partition defensively
        // rather than rely on a stable sort under PHP 7.4.
        $ordered = [];
        foreach ($enabled as $code) {
            if ($code === $default) {
                array_unshift($ordered, $code);
            } else {
                $ordered[] = $code;
            }
        }

        $path    = $this->prefixer->stripPrefix($this->currentPath());
        $query   = $this->currentQuery();

        $rows = [];
        foreach ($ordered as $code) {
            // Trailing-slash shape is settled inside the builder now: it is
            // preserved through stripPrefix()/addPrefix() for every path, and
            // for the language home it follows the site's permalink structure.
            // The fix-up that used to live here forced the request's own shape,
            // which on a site without trailing slashes produced the redirect it
            // was added to avoid.
            $localized = $this->prefixer->addPrefix($path, $code);

            $rows[] = [
                'code'         => $code,
                'slug'         => $this->resolver->slugForLanguage($code),
                // The request path carries any site folder (/blog/…), and the
                // builder keeps it in place; absoluteUrl() adds only scheme+host.
                'url'          => $this->prefixer->absoluteUrl($localized . $query),
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

    /**
     * Keep only the given codes, in the given order. Unknown codes are skipped.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, mixed>                $codes
     * @return array<int, array<string, mixed>>
     */
    private function only(array $rows, array $codes): array
    {
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[$row['code']] = $row;
        }

        // Keyed by code, so a locale listed twice ("fr_FR,fr_FR") appears once,
        // at its first position. PHP keeps insertion order for string keys.
        $out = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            if (isset($byCode[$code]) && !isset($out[$code])) {
                $out[$code] = $byCode[$code];
            }
        }

        return array_values($out);
    }

    private function currentPath(): string
    {
        $uri  = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);

        return $path === '' ? '/' : $path;
    }

    private function currentQuery(): string
    {
        $uri   = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $query = (string) wp_parse_url($uri, PHP_URL_QUERY);

        return $query !== '' ? '?' . $query : '';
    }
}

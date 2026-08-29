<?php

namespace LingoWP\LocalizationRouting;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\SupportedLanguages;

/**
 * Renders the language switcher and registers the [lingowp_language_switcher]
 * shortcode.
 *
 * For each enabled language it builds the URL of the CURRENT page in that
 * language, using the same LocalizedUrlBuilder as the rest of public URL
 * handling. The active language is marked but still linked (harmless, and
 * useful for screen readers).
 *
 * Selection behavior: the links point straight at the canonical language URL
 * (e.g. /fr/about), so clicking one navigates there directly — the URL stays
 * canonical. A lightweight click handler also records preferred_lang as a
 * preference cookie, without ever overriding the URL.
 */
class LanguageSwitcherShortcode
{
    public const SHORTCODE = 'lingowp_language_switcher';

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

    public function register(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * Shortcode entry point. Attributes:
     *   show: "native" (default) | "english" | "code"
     *
     * @param array<string, string>|string $atts
     */
    public function shortcode($atts = []): string
    {
        $atts = shortcode_atts(['show' => 'native'], (array) $atts, self::SHORTCODE);

        return $this->render((string) $atts['show']);
    }

    /**
     * Build the switcher markup. Safe to call directly from a theme template.
     */
    public function render(string $show = 'native'): string
    {
        $languages = $this->resolver->getEnabledLanguages();

        if (count($languages) < 2) {
            return ''; // nothing to switch between
        }

        $active  = $this->resolver->resolve();
        $path    = $this->currentPath();
        $query   = $this->currentQuery();
        $default = $this->resolver->getDefaultLanguage();

        // Stable display order: default language first, then the rest as enabled.
        usort($languages, static function (string $a, string $b) use ($default): int {
            if ($a === $default) {
                return -1;
            }
            if ($b === $default) {
                return 1;
            }
            return 0;
        });

        $items = '';

        foreach ($languages as $lang) {
            $url      = $this->prefixer->addPrefix($path, $lang) . $query;
            $label    = $this->labelFor($lang, $show);
            $isActive = $lang === $active;
            $htmlLang = str_replace('_', '-', $lang);

            $items .= sprintf(
                '<li class="lingowp-language-switcher__item%1$s">'
                . '<a href="%2$s" hreflang="%3$s" lang="%3$s" data-lingowp-lang="%4$s"%5$s>%6$s</a>'
                . '</li>',
                $isActive ? ' is-active' : '',
                esc_url($url),
                esc_attr($htmlLang),
                esc_attr($lang),
                $isActive ? ' aria-current="true"' : '',
                esc_html($label)
            );
        }

        return '<ul class="lingowp-language-switcher">' . $items . '</ul>';
    }

    /**
     * Tiny inline script: when a switcher link is clicked, drop the preference
     * cookie, then let the navigation proceed to the canonical URL as normal.
     * The cookie is preference-only; it never overrides the URL.
     */
    public function enqueue(): void
    {
        if (file_exists(LINGOWP_DIR . 'assets/public/switcher.css')) {
            wp_enqueue_style(
                'lingowp-switcher',
                LINGOWP_URL . 'assets/public/switcher.css',
                [],
                LINGOWP_VERSION
            );
        }

        // Register a no-src handle so we can attach inline JS to it.
        wp_register_script('lingowp-switcher', false, [], LINGOWP_VERSION, true);
        wp_enqueue_script('lingowp-switcher');

        $cookie = ResolveRequestLanguage::COOKIE_NAME;

        $js = <<<JS
document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-lingowp-lang]');
    if (!a) { return; }
    var lang = a.getAttribute('data-lingowp-lang');
    if (lang) {
        document.cookie = '{$cookie}=' + encodeURIComponent(lang) + '; path=/; max-age=31536000; SameSite=Lax';
    }
    // Do not preventDefault: navigation continues to the canonical URL.
});
JS;

        wp_add_inline_script('lingowp-switcher', $js);
    }

    // ------------------------------------------------------------------

    /**
     * Current request path with any existing language prefix removed, so each
     * switcher option can apply its own prefix from a clean base.
     */
    private function currentPath(): string
    {
        $uri  = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);

        if ($path === '') {
            $path = '/';
        }

        return $this->prefixer->stripPrefix($path);
    }

    /**
     * Current query string, prefixed with "?" if present, else empty.
     */
    private function currentQuery(): string
    {
        $uri   = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $query = (string) wp_parse_url($uri, PHP_URL_QUERY);

        return $query !== '' ? '?' . $query : '';
    }

    private function labelFor(string $lang, string $show): string
    {
        switch ($show) {
            case 'english':
                return $this->languages->label($lang);
            case 'code':
                return strtoupper($lang);
            default:
                return $this->languages->nativeLabel($lang);
        }
    }
}

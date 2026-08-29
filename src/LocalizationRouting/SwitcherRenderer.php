<?php

namespace LingoWP\LocalizationRouting;

use LingoWP\Language\Application\ResolveRequestLanguage;

/**
 * The bundled language switcher: turns LanguageLinks rows into one fixed markup
 * contract theme CSS can target. Every first-party surface renders through here,
 * so they are byte-identical for identical args.
 *
 * Frozen markup — users write CSS against these classes:
 *
 *   <nav class="lingowp-switcher lingowp-switcher--dropdown" data-lingowp-switcher aria-label="Language">
 *     <details class="lingowp-switcher__details">              (dropdown layout only)
 *       <summary class="lingowp-switcher__current">Français</summary>
 *       <ul class="lingowp-switcher__list">
 *         <li class="lingowp-switcher__item is-current">
 *           <a class="lingowp-switcher__link" href="/fr/about/" hreflang="fr" lang="fr"
 *              aria-current="true" data-lingowp-lang="fr_FR">Français</a>
 *         </li>
 *       </ul>
 *     </details>
 *   </nav>
 *
 * The dropdown is a <details> element — keyboard-operable with the stylesheet
 * alone, no JavaScript. A small inline script still records the language
 * preference cookie on click, but navigation never depends on it.
 */
class SwitcherRenderer
{
    private const LAYOUTS  = ['dropdown', 'inline', 'list'];
    private const DISPLAYS = ['native', 'english', 'code'];

    private static ?self $primed = null;

    private bool $rendered = false;

    private LanguageLinks $links;

    public function __construct(LanguageLinks $links)
    {
        $this->links = $links;
    }

    public static function instance(): self
    {
        return self::$primed instanceof self ? self::$primed : new self(LanguageLinks::instance());
    }

    public static function prime(self $instance): void
    {
        self::$primed = $instance;
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
        add_action('wp_footer', [$this, 'enqueueIfRendered'], 1);
    }

    /**
     * @param array<string, mixed> $args {
     *   @type string          $display       'native' (default) | 'english' | 'code'.
     *   @type string          $layout        'dropdown' (default) | 'inline' | 'list'.
     *   @type bool            $hide_current  Drop the active language. Default false.
     *   @type string          $class         Extra CSS classes for the wrapper.
     *   @type string[]        $include       Passed through to LanguageLinks.
     *   @type string|string[] $order         Passed through to LanguageLinks.
     * }
     */
    public function render(array $args = []): string
    {
        $display = in_array($args['display'] ?? '', self::DISPLAYS, true) ? (string) $args['display'] : 'native';
        $layout  = in_array($args['layout'] ?? '', self::LAYOUTS, true) ? (string) $args['layout'] : 'dropdown';
        $extra   = trim((string) ($args['class'] ?? ''));

        $rows = $this->links->links([
            'hide_current' => !empty($args['hide_current']),
            'include'      => $args['include'] ?? null,
            'order'        => $args['order'] ?? 'settings',
        ]);

        if ($rows === []) {
            return '';
        }

        $this->rendered = true;

        $items = '';
        foreach ($rows as $row) {
            $items .= $this->item($row, $display);
        }
        $list = '<ul class="lingowp-switcher__list">' . $items . '</ul>';

        if ($layout === 'dropdown') {
            $list = '<details class="lingowp-switcher__details">'
                . '<summary class="lingowp-switcher__current">' . esc_html($this->summaryLabel($rows, $display)) . '</summary>'
                . $list
                . '</details>';
        }

        $classes = 'lingowp-switcher lingowp-switcher--' . $layout . ($extra !== '' ? ' ' . $extra : '');

        return sprintf(
            '<nav class="%s" data-lingowp-switcher aria-label="%s">%s</nav>',
            esc_attr($classes),
            esc_attr__('Language', 'lingowp'),
            $list
        );
    }

    /** Whether render() produced markup this request — drives the conditional enqueue. */
    public function wasRendered(): bool
    {
        return $this->rendered;
    }

    public function registerAssets(): void
    {
        if (file_exists(LINGOWP_DIR . 'assets/public/switcher.css')) {
            wp_register_style('lingowp-switcher', LINGOWP_URL . 'assets/public/switcher.css', [], LINGOWP_VERSION);
        }

        wp_register_script('lingowp-switcher', false, [], LINGOWP_VERSION, true);
        wp_add_inline_script('lingowp-switcher', $this->cookieScript());
    }

    public function enqueueIfRendered(): void
    {
        if (!$this->rendered) {
            return;
        }

        if (wp_style_is('lingowp-switcher', 'registered')) {
            wp_enqueue_style('lingowp-switcher');
        }
        wp_enqueue_script('lingowp-switcher');
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     */
    private function item(array $row, string $display): string
    {
        $tag   = str_replace('_', '-', (string) $row['code']);
        $attrs = sprintf(
            'class="lingowp-switcher__link" href="%s" hreflang="%s" lang="%s" data-lingowp-lang="%s"',
            esc_url((string) $row['url']),
            esc_attr($tag),
            esc_attr($tag),
            esc_attr((string) $row['code'])
        );

        if (($row['dir'] ?? 'ltr') === 'rtl') {
            $attrs .= ' dir="rtl"';
        }
        if (!empty($row['current'])) {
            $attrs .= ' aria-current="true"';
        }

        return sprintf(
            '<li class="lingowp-switcher__item%s"><a %s>%s</a></li>',
            empty($row['current']) ? '' : ' is-current',
            $attrs,
            esc_html($this->labelFor($row, $display))
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function labelFor(array $row, string $display): string
    {
        switch ($display) {
            case 'english':
                return (string) $row['english_name'];
            case 'code':
                return strtoupper((string) $row['code']);
            default:
                return (string) $row['native_name'];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function summaryLabel(array $rows, string $display): string
    {
        foreach ($rows as $row) {
            if (!empty($row['current'])) {
                return $this->labelFor($row, $display);
            }
        }

        return $this->labelFor($rows[0], $display);
    }

    /**
     * Records the language-preference cookie on click. Navigation proceeds to
     * the canonical URL regardless — this never calls preventDefault.
     */
    private function cookieScript(): string
    {
        $cookie = ResolveRequestLanguage::COOKIE_NAME;

        return <<<JS
document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-lingowp-lang]');
    if (!a) { return; }
    var lang = a.getAttribute('data-lingowp-lang');
    if (lang) {
        document.cookie = '{$cookie}=' + encodeURIComponent(lang) + '; path=/; max-age=31536000; SameSite=Lax';
    }
});
JS;
    }
}

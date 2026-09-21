<?php

namespace LingoWP\LocalizationRouting;

class SwitcherRenderer
{
    private const LAYOUTS   = ['dropdown', 'inline', 'list'];
    private const DISPLAYS  = ['native', 'english', 'code'];
    private const FALLBACKS = ['globe', 'code', 'none'];
    private const SIZES     = ['sm', 'md', 'lg'];

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

    public function render(array $args = []): string
    {
        $display  = in_array($args['display'] ?? '', self::DISPLAYS, true) ? (string) $args['display'] : 'native';
        $layout   = in_array($args['layout'] ?? '', self::LAYOUTS, true) ? (string) $args['layout'] : 'dropdown';
        $fallback = in_array($args['flag_fallback'] ?? '', self::FALLBACKS, true) ? (string) $args['flag_fallback'] : 'globe';
        $flags    = $this->resolveFlags($args['flags'] ?? null, $layout);
        $size     = in_array($args['size'] ?? '', self::SIZES, true) ? (string) $args['size'] : 'lg';
        $shadow   = !array_key_exists('shadow', $args) || filter_var($args['shadow'], FILTER_VALIDATE_BOOLEAN);
        $extra    = trim((string) ($args['class'] ?? ''));
        $label    = trim((string) ($args['label'] ?? '')) !== '' ? (string) $args['label'] : __('Language', 'lingowp');
        $id       = trim((string) ($args['id'] ?? ''));

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
            $items .= $this->item($row, $display, $flags, $fallback);
        }
        $list = '<ul class="lingowp-switcher__list">' . $items . '</ul>';

        if ($layout === 'dropdown') {
            $list = '<details class="lingowp-switcher__details">'
                . '<summary class="lingowp-switcher__current">' . $this->currentMarkup($rows, $display, $flags, $fallback) . '</summary>'
                . $list
                . '</details>';
        }

        $classes = 'lingowp-switcher lingowp-switcher--' . $layout
            . ' lingowp-switcher--' . $size
            . ($shadow ? ' lingowp-switcher--shadow' : '')
            . ($extra !== '' ? ' ' . $extra : '');

        return sprintf(
            '<nav class="%s"%s data-lingowp-switcher aria-label="%s">%s</nav>',
            esc_attr($classes),
            $id !== '' ? ' id="' . esc_attr($id) . '"' : '',
            esc_attr($label),
            $list
        );
    }

    public function wasRendered(): bool
    {
        return $this->rendered;
    }

    public function registerAssets(): void
    {
        $cssFile = LINGOWP_DIR . 'assets/public/switcher.css';
        if (file_exists($cssFile)) {
            wp_register_style('lingowp-switcher', LINGOWP_URL . 'assets/public/switcher.css', [], (string) filemtime($cssFile));
        }

        $jsFile = LINGOWP_DIR . 'assets/public/switcher.js';
        if (file_exists($jsFile)) {
            wp_register_script('lingowp-switcher', LINGOWP_URL . 'assets/public/switcher.js', [], (string) filemtime($jsFile), true);
        }
    }

    public function enqueueIfRendered(): void
    {
        if (!$this->rendered) {
            return;
        }

        if (wp_style_is('lingowp-switcher', 'registered')) {
            wp_enqueue_style('lingowp-switcher');
        }
        if (wp_script_is('lingowp-switcher', 'registered')) {
            wp_enqueue_script('lingowp-switcher');
        }
    }

    private function resolveFlags($raw, string $layout)
    {
        if ($raw === 'only') {
            return 'only';
        }
        if ($raw === null) {
            return $layout === 'dropdown';
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    private function item(array $row, string $display, $flags, string $fallback): string
    {
        $tag   = str_replace('_', '-', (string) $row['code']);
        $attrs = sprintf(
            'class="lingowp-switcher__link" href="%s" hreflang="%s" lang="%s" data-lingowp-lang="%s"',
            esc_url((string) $row['url']),
            esc_attr($tag),
            esc_attr($tag),
            esc_attr((string) $row['code'])
        );

        if (!empty($row['current'])) {
            $attrs .= ' aria-current="true"';
        }

        return sprintf(
            '<li class="lingowp-switcher__item%s"><a %s>%s</a></li>',
            empty($row['current']) ? '' : ' is-current',
            $attrs,
            $this->rowMarkup($row, $display, $flags, $fallback)
        );
    }

    private function rowMarkup(array $row, string $display, $flags, string $fallback): string
    {
        $flagSlot = $flags === false ? '' : $this->flagSlot($row, $fallback);
        $text     = $this->labelFor($row, $display);

        if ($flags === 'only' && $flagSlot !== '') {
            return $flagSlot . '<span class="lingowp-switcher__sr">' . esc_html($text) . '</span>';
        }

        return $flagSlot . esc_html($text);
    }

    private function flagSlot(array $row, string $fallback): string
    {
        $url = (string) ($row['flag_url'] ?? '');
        if ($url !== '') {
            return sprintf(
                '<span class="lingowp-switcher__flag"><img class="lingowp-switcher__flag-img" src="%s" alt="" width="20" height="15" decoding="async"></span>',
                esc_url($url)
            );
        }

        if ($fallback === 'globe') {
            return '<span class="lingowp-switcher__flag">' . $this->globeSvg() . '</span>';
        }
        if ($fallback === 'code') {
            $code = (string) ($row['region'] ?? '') !== ''
                ? (string) $row['region']
                : strtoupper((string) $row['code']);

            return '<span class="lingowp-switcher__flag lingowp-switcher__flag--code">' . esc_html($code) . '</span>';
        }

        return '';
    }

    private function globeSvg(): string
    {
        return '<svg class="lingowp-switcher__globe" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false">'
            . '<path fill="currentColor" d="M8 0a8 8 0 100 16A8 8 0 008 0zm5.917 7H11.5c-.117-2.02-.67-3.77-1.463-4.957A6.51 6.51 0 0113.917 7zM8 1.5c.98 0 2.07 2.03 2.24 5.5H5.76C5.93 3.53 7.02 1.5 8 1.5zM2.083 7A6.51 6.51 0 015.963 2.043C5.17 3.23 4.617 4.98 4.5 7H2.083zm0 2H4.5c.117 2.02.67 3.77 1.463 4.957A6.51 6.51 0 012.083 9zM8 14.5c-.98 0-2.07-2.03-2.24-5.5h4.48C10.07 12.47 8.98 14.5 8 14.5zm2.037-.543C10.83 12.77 11.383 11.02 11.5 9h2.417a6.51 6.51 0 01-3.88 4.957z"/>'
            . '</svg>';
    }

    private function labelFor(array $row, string $display): string
    {
        if ($display === 'code') {
            $lang   = strtoupper(explode('_', (string) $row['code'])[0]);
            $region = (string) ($row['region'] ?? '');

            return $region !== '' ? $lang . ' (' . $region . ')' : $lang;
        }

        return $display === 'english'
            ? (string) $row['english_name']
            : (string) $row['native_name'];
    }

    private function currentMarkup(array $rows, string $display, $flags, string $fallback): string
    {
        foreach ($rows as $row) {
            if (!empty($row['current'])) {
                return $this->rowMarkup($row, $display, $flags, $fallback);
            }
        }

        return $this->rowMarkup($rows[0], $display, $flags, $fallback);
    }
}

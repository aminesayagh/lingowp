<?php

namespace LingoWP\Switcher;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Domain\FlagResolver;
use LingoWP\Language\Infrastructure\WordPressLanguageCatalog;
use LingoWP\LocalizationRouting\LanguageLinks;

class Switcher
{
    private const LAYOUTS        = ['dropdown', 'horizontal', 'vertical'];
    private const LABEL_FORMATS  = ['full', 'short', 'none'];
    private const NAME_SOURCES   = ['native', 'english', 'current'];
    private const FLAG_POSITIONS = ['before', 'after', 'hidden'];
    private const OPEN_ON        = ['click', 'hover'];

    private const LEGACY_LAYOUTS = ['inline' => 'horizontal', 'list' => 'vertical'];

    private static ?self $primed = null;

    private static int $instances = 0;

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
        add_action('wp_head', [$this, 'printJsFlag'], 1);
        add_filter('script_loader_tag', [$this, 'excludeFromRocketLoader'], 10, 2);
        add_filter('pre_render_block', [$this, 'skipAutopForRenderedSwitcher'], 10, 2);
        add_action('wp_footer', [$this, 'renderFloating'], 5);
    }

    public function render(array $args = []): string
    {
        $options = $this->normalize($args);

        if ($options['floating'] !== '') {
            $options['layout']       = 'dropdown';
            $options['button_width'] = 'fit';
        }

        $alpha = $options['order'] === 'alpha';
        $rows  = $this->links->links([
            'include' => $options['include'],
            'order'   => $alpha ? 'settings' : $options['order'],
        ]);

        if ($rows === []) {
            return '';
        }

        $current = $rows[0];
        foreach ($this->links->links() as $row) {
            if (!empty($row['current'])) {
                $current = $row;
                break;
            }
        }

        $options['display_locale'] = (string) $current['code'];
        $options['display_dir']    = (string) $current['dir'];

        $options['square_flags'] = SwitcherSettings::squareFlags($options['design'] + SwitcherSettings::get()['design']);

        $choices = $options['hide_current']
            ? array_filter($rows, static fn(array $row): bool => empty($row['current']))
            : $rows;

        if ($choices === []) {
            return '';
        }

        $items = [];
        foreach ($choices as $row) {
            $items[] = $this->item($row, $options, $rows);
        }

        if ($alpha) {
            $items = $this->sortByLabel($items, (string) $options['display_locale']);
        }

        $id = $options['id'] !== '' ? $options['id'] : 'lingowp-switcher-' . (++self::$instances);

        $boxed = SwitcherSettings::isBoxed($options['design'] + SwitcherSettings::get()['design'])
            || $options['floating'] !== '';

        $cookie = (bool) get_option('lingowp_cookie_preference', true) ? ResolveRequestLanguage::COOKIE_NAME : '';

        $switcher = [
            'id'      => $id,
            'list_id' => $id . '-list',
            'layout'  => $options['layout'],
            'classes' => trim(
                'lingowp-switcher lingowp-switcher--' . $options['layout']
                . ' lingowp-switcher--' . $options['size']
                . ($boxed ? ' lingowp-switcher--boxed' : '')
                . ($options['layout'] === 'dropdown' && $boxed && $options['button_width'] === 'full' ? ' lingowp-switcher--full' : '')
                . ($options['floating'] !== ''
                    ? ' lingowp-switcher--floating lingowp-switcher--floating-' . ($options['floating'] === 'bottom-left' ? 'left' : 'right')
                    : '')
                . ($options['layout'] === 'dropdown' && $options['show_arrow'] ? ' lingowp-switcher--arrow' : '')
                . ($options['layout'] === 'dropdown' && $options['dropdown_animation'] ? ' lingowp-switcher--animate' : '')
                . ($options['layout'] === 'dropdown' && $options['dropdown_shadow'] !== 'none' ? ' lingowp-switcher--shadow-' . $options['dropdown_shadow'] : '')
                . ' ' . $options['class']
            ),
            'style'   => trim(
                SwitcherSettings::cssVariables($options['design'])
                . ($options['floating_offset'] !== '' ? ';--lingowp-switcher-floating-offset:' . $options['floating_offset'] : ''),
                ';'
            ),
            'label'   => $options['label'],
            'open_on' => $options['open_on'],
            'cookie'  => $cookie,
            'current' => $this->item($current, $options, $rows),
            'items'   => $items,
        ];

        $this->enqueueAssets();

        ob_start();
        include $this->templatePath();

        return (string) ob_get_clean();
    }

    public function normalize(array $args): array
    {
        $legacyDisplay = (string) (($args['show'] ?? '') !== '' ? $args['show'] : ($args['display'] ?? ''));
        $legacyFlags   = $args['flags'] ?? null;

        $layout = (string) ($args['layout'] ?? '');
        $layout = self::LEGACY_LAYOUTS[$layout] ?? $layout;

        $labelFormat = $args['label_format']
            ?? ($legacyFlags === 'only' ? 'none' : ($legacyDisplay === 'code' ? 'short' : 'full'));

        $flagPosition = $args['flag_position']
            ?? ($legacyFlags !== null && $legacyFlags !== 'only' && !filter_var($legacyFlags, FILTER_VALIDATE_BOOLEAN) ? 'hidden' : 'before');

        $options = [
            'layout'        => self::pick($layout, self::LAYOUTS),
            'label_format'  => self::pick($labelFormat, self::LABEL_FORMATS),
            'name_source'   => self::pick($args['name_source'] ?? $legacyDisplay, self::NAME_SOURCES),
            'flag_position' => self::pick($flagPosition, self::FLAG_POSITIONS),
            'open_on'       => self::pick($args['open_on'] ?? '', self::OPEN_ON),
            'hide_current'  => filter_var($args['hide_current'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'include'       => is_array($args['include'] ?? null) ? $args['include'] : null,
            'order'         => $args['order'] ?? 'settings',
            'label'         => trim((string) ($args['label'] ?? '')) !== '' ? trim((string) $args['label']) : __('Language', 'lingowp'),
            'id'            => trim((string) ($args['id'] ?? '')),
            'class'         => trim((string) ($args['class'] ?? '')),
            'design'        => SwitcherSettings::sanitizeDesign($args),
            'size'          => SwitcherSettings::validSize($args['size'] ?? null) ?? SwitcherSettings::get()['size'],
            'dropdown_shadow' => SwitcherSettings::validShadow($args['dropdown_shadow'] ?? null)
                ?? (isset($args['shadow']) && $args['shadow'] !== '' && !filter_var($args['shadow'], FILTER_VALIDATE_BOOLEAN) ? 'none' : null)
                ?? SwitcherSettings::get()['dropdown_shadow'],
            'button_width'  => SwitcherSettings::validButtonWidth($args['button_width'] ?? null) ?? SwitcherSettings::get()['button_width'],
            'floating'        => SwitcherSettings::validFloatingPosition($args['floating'] ?? null) ?? '',
            'floating_offset' => SwitcherSettings::sanitizeValue('floating_offset', $args['floating_offset'] ?? ''),
            'dropdown_animation' => isset($args['dropdown_animation']) && $args['dropdown_animation'] !== ''
                ? filter_var($args['dropdown_animation'], FILTER_VALIDATE_BOOLEAN)
                : SwitcherSettings::get()['dropdown_animation'],
            'show_arrow'    => isset($args['show_arrow']) && $args['show_arrow'] !== ''
                ? filter_var($args['show_arrow'], FILTER_VALIDATE_BOOLEAN)
                : SwitcherSettings::get()['show_arrow'],
        ];

        if ($options['label_format'] === 'none' && $options['flag_position'] === 'hidden') {
            $options['label_format'] = 'full';
        }

        return $options;
    }

    public function renderFloating(): void
    {
        $settings = SwitcherSettings::get();
        if (!$settings['floating_enabled']) {
            return;
        }

        $html = $this->render([
            'floating'        => $settings['floating_position'],
            'floating_offset' => $settings['floating_offset'],
            'id'              => 'lingowp-floating-switcher',
            'label'           => __('Language', 'lingowp'),
        ]);

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the switcher template escapes every value
    }

    public function registerAssets(): void
    {
        $dir = LINGOWP_DIR . 'assets/switcher/build/';
        $url = LINGOWP_URL . 'assets/switcher/build/';

        if (file_exists($dir . 'switcher.css')) {
            wp_register_style('lingowp-switcher', $url . 'switcher.css', [], (string) filemtime($dir . 'switcher.css'));
        }
        if (file_exists($dir . 'switcher.js')) {
            wp_register_script('lingowp-switcher', $url . 'switcher.js', [], (string) filemtime($dir . 'switcher.js'), true);
        }

        wp_enqueue_style('lingowp-switcher');
    }

    public function printJsFlag(): void
    {
        wp_print_inline_script_tag(
            "(function(d,w){var c=d.documentElement.classList;c.add('lingowp-js');"
            . "w.addEventListener('load',function(){if(!w.lingowpSwitcher){c.remove('lingowp-js');}});}(document,window));",
            ['data-cfasync' => 'false']
        );
    }

    public function excludeFromRocketLoader($tag, $handle): string
    {
        if ($handle !== 'lingowp-switcher') {
            return (string) $tag;
        }

        return str_replace('<script ', '<script data-cfasync="false" ', (string) $tag);
    }

    public function skipAutopForRenderedSwitcher($pre, $block)
    {
        if ($pre !== null || ($block['blockName'] ?? '') !== 'core/shortcode') {
            return $pre;
        }

        $html = (string) ($block['innerHTML'] ?? '');

        return strpos($html, 'data-lingowp-switcher') !== false ? $html : null;
    }

    private function enqueueAssets(): void
    {
        wp_enqueue_style('lingowp-switcher');
        wp_enqueue_script('lingowp-switcher');
    }

    private function item(array $row, array $options, array $rows): array
    {
        $flag       = $options['flag_position'] === 'hidden' ? '' : (string) ($row['flag_url'] ?? '');
        if ($flag !== '' && !empty($options['square_flags'])) {
            $flag = FlagResolver::url((string) $row['code'], '1x1') ?? $flag;
        }
        $showRegion = $this->showsRegion($row, $rows);
        $tag        = str_replace('_', '-', (string) $row['code']);

        if ($options['name_source'] === 'english' || ($options['name_source'] === 'current' && !class_exists(\Locale::class))) {
            $full     = (string) $row['english_name'];
            $textLang = 'en';
            $textDir  = 'ltr';
        } elseif ($options['name_source'] === 'current') {
            $full     = WordPressLanguageCatalog::displayIn((string) $row['code'], (string) $options['display_locale'], (string) $row['english_name']);
            $textLang = str_replace('_', '-', (string) $options['display_locale']);
            $textDir  = (string) $options['display_dir'];
        } else {
            $full     = (string) $row['native_name'];
            $textLang = $tag;
            $textDir  = (string) $row['dir'];
        }

        $fullLang = $textLang;
        $fullDir  = $textDir;

        $short = $options['label_format'] === 'short';
        if ($short) {
            $textLang = $tag;
            $textDir  = (string) $row['dir'];
        }

        if (!$showRegion && (string) ($row['region'] ?? '') !== '') {
            $full = (string) preg_replace('/\s*\([^()]*\)$/u', '', $full) ?: $full;
        }

        $text = $short ? $this->shortName($row, $showRegion) : $full;

        return [
            'code'          => (string) $row['code'],
            'url'           => (string) $row['url'],
            'language_tag'  => $tag,
            'dir'           => (string) $row['dir'],
            'current'       => !empty($row['current']),
            'text'          => $text,
            'text_visible'  => $options['label_format'] !== 'none' || $flag === '',
            'text_lang'     => $textLang,
            'text_dir'      => $textDir,
            'sr_text'       => $short ? $text . ', ' . $full : '',
            'sr_lang'       => $fullLang,
            'sr_dir'        => $fullDir,
            'flag_url'      => $flag,
            'flag_square'   => $flag !== '' && !empty($options['square_flags']),
            'flag_position' => $options['flag_position'],
        ];
    }

    private function showsRegion(array $row, array $rows): bool
    {
        $language = self::languageOf($row);
        $region   = strtolower((string) ($row['region'] ?? ''));

        if ($region === '' || $region === $language) {
            return false;
        }

        foreach ($rows as $other) {
            if ($other['code'] !== $row['code'] && self::languageOf($other) === $language) {
                return true;
            }
        }

        return false;
    }

    private function shortName(array $row, bool $showRegion): string
    {
        $language = strtoupper(self::languageOf($row));

        return $showRegion ? $language . '-' . strtoupper((string) $row['region']) : $language;
    }

    private static function languageOf(array $row): string
    {
        return strtolower(explode('_', (string) $row['code'])[0]);
    }

    private function templatePath(): string
    {
        $override = locate_template('lingowp/switcher.php');

        return $override !== '' ? $override : __DIR__ . '/templates/switcher.php';
    }

    private function sortByLabel(array $items, string $locale): array
    {
        $collator = class_exists(\Collator::class) ? new \Collator($locale) : null;
        $plain    = static fn(string $text): string => function_exists('remove_accents') ? remove_accents($text) : $text;

        usort($items, static function (array $a, array $b) use ($collator, $plain): int {
            return $collator !== null
                ? (int) $collator->compare((string) $a['text'], (string) $b['text'])
                : strcasecmp($plain((string) $a['text']), $plain((string) $b['text']));
        });

        return $items;
    }

    private static function pick($value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? (string) $value : $allowed[0];
    }
}

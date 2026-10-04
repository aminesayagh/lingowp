<?php

namespace LingoWP\Switcher;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\LocalizationRouting\LanguageLinks;

/**
 * The language switcher behind lingowp_switcher(), lingowp_get_switcher() and
 * [lingowp_language_switcher].
 *
 * Flow: caller options -> normalize() -> LanguageLinks rows -> one view array
 * -> templates/switcher.php (or a theme's lingowp/switcher.php override).
 *
 * Language URLs are never built here. LanguageLinks owns them, so the switcher,
 * hreflang and redirects always agree on where each language lives. Visitors
 * follow ordinary links; the active language is decided by the URL on the next
 * request (ResolveRequestLanguage), and the bundled script only records the
 * preference cookie on click.
 */
class Switcher
{
    private const LAYOUTS        = ['dropdown', 'horizontal', 'vertical'];
    private const LABEL_FORMATS  = ['full', 'short', 'none'];
    private const NAME_SOURCES   = ['native', 'english'];
    private const FLAG_POSITIONS = ['before', 'after', 'hidden'];
    private const OPEN_ON        = ['click', 'hover'];

    /** Previous switcher's layout names. */
    private const LEGACY_LAYOUTS = ['inline' => 'horizontal', 'list' => 'vertical'];

    private static ?self $primed = null;

    /** Numbers instance ids so several switchers on one page never collide. */
    private static int $instances = 0;

    private LanguageLinks $links;

    public function __construct(LanguageLinks $links)
    {
        $this->links = $links;
    }

    /**
     * The instance primed at boot, or a self-built one for a theme that calls
     * lingowp_get_switcher() before the plugin finished booting.
     */
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
    }

    /**
     * @param array<string, mixed> $args See normalize().
     */
    public function render(array $args = []): string
    {
        $options = $this->normalize($args);

        // hide_current is applied here, not in LanguageLinks: the dropdown
        // trigger still has to show the active language after it is removed
        // from the choices.
        $rows = $this->links->links([
            'include' => $options['include'],
            'order'   => $options['order'],
        ]);

        if ($rows === []) {
            return '';
        }

        $current = $rows[0];
        foreach ($rows as $row) {
            if (!empty($row['current'])) {
                $current = $row;
                break;
            }
        }

        $choices = $options['hide_current']
            ? array_filter($rows, static fn(array $row): bool => empty($row['current']))
            : $rows;

        $items = [];
        foreach ($choices as $row) {
            $items[] = $this->item($row, $options, $rows);
        }

        $id = $options['id'] !== '' ? $options['id'] : 'lingowp-switcher-' . (++self::$instances);

        // Same option ResolveRequestLanguage reads before it writes the cookie:
        // when the admin turns the preference cookie off, the script must not
        // write it either.
        $cookie = (bool) get_option('lingowp_cookie_preference', true) ? ResolveRequestLanguage::COOKIE_NAME : '';

        $switcher = [
            'id'      => $id,
            'list_id' => $id . '-list',
            'layout'  => $options['layout'],
            'classes' => trim('lingowp-switcher lingowp-switcher--' . $options['layout'] . ' ' . $options['class']),
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

    /**
     * Caller options -> one validated option set. Unknown values fall back to
     * the default rather than erroring, since they usually come from shortcode
     * text typed by a site owner.
     *
     * Also maps the previous switcher's arguments so existing shortcodes and
     * theme calls keep rendering:
     *   display|show = native|english|code  -> name_source / label_format=short
     *   flags = true|false|only             -> flag_position / label_format=none
     *   layout = inline|list                -> horizontal|vertical
     *   size, shadow, flag_fallback         -> accepted, not used yet
     *
     * @param array<string, mixed> $args {
     *   @type string          $layout         'dropdown' (default) | 'horizontal' | 'vertical'.
     *   @type string          $label_format   'full' (default) | 'short' | 'none'.
     *   @type string          $name_source    'native' (default) | 'english' — for full labels.
     *   @type string          $flag_position  'before' (default) | 'after' | 'hidden'.
     *   @type string          $open_on        'click' (default) | 'hover' — dropdown only.
     *   @type bool            $hide_current   Drop the active language from the choices.
     *   @type string[]        $include        Keep only these locales.
     *   @type string|string[] $order          'settings' (default) | 'alpha' | a locale list.
     *   @type string          $label          The nav's accessible name. Default "Language".
     *   @type string          $id             Wrapper id. Default generated.
     *   @type string          $class          Extra wrapper classes.
     * }
     * @return array<string, mixed>
     */
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
        ];

        // Never hide both the text and the flag — the link would be empty.
        if ($options['label_format'] === 'none' && $options['flag_position'] === 'hidden') {
            $options['label_format'] = 'full';
        }

        return $options;
    }

    public function registerAssets(): void
    {
        $dir = LINGOWP_DIR . 'assets/switcher/build/';
        $url = LINGOWP_URL . 'assets/switcher/build/';

        // filemtime() as the version: a rebuilt bundle busts caches without a
        // plugin version bump.
        if (file_exists($dir . 'switcher.css')) {
            wp_register_style('lingowp-switcher', $url . 'switcher.css', [], (string) filemtime($dir . 'switcher.css'));
        }
        if (file_exists($dir . 'switcher.js')) {
            wp_register_script('lingowp-switcher', $url . 'switcher.js', [], (string) filemtime($dir . 'switcher.js'), true);
        }
    }

    // ------------------------------------------------------------------

    /**
     * ponytail: enqueued when a switcher renders, so one placed in the page body
     * gets its stylesheet printed in the footer (a brief unstyled flash). The
     * floating and menu surfaces will enqueue on wp_enqueue_scripts once their
     * settings exist, since they know before the page renders.
     */
    private function enqueueAssets(): void
    {
        wp_enqueue_style('lingowp-switcher');
        wp_enqueue_script('lingowp-switcher');
    }

    /**
     * One language as the template needs it.
     *
     * @param array<string, mixed>             $row     A LanguageLinks row.
     * @param array<string, mixed>             $options normalize() output.
     * @param array<int, array<string, mixed>> $rows    Every listed language, for short names.
     * @return array<string, mixed>
     */
    private function item(array $row, array $options, array $rows): array
    {
        $flag = $options['flag_position'] === 'hidden' ? '' : (string) ($row['flag_url'] ?? '');
        $full = $options['name_source'] === 'english' ? (string) $row['english_name'] : (string) $row['native_name'];

        return [
            'code'          => (string) $row['code'],
            'url'           => (string) $row['url'],
            'language_tag'  => str_replace('_', '-', (string) $row['code']),
            'dir'           => (string) $row['dir'],
            'current'       => !empty($row['current']),
            'text'          => $options['label_format'] === 'short' ? $this->shortName($row, $rows) : $full,
            // A hidden label stays in the markup for screen readers — and
            // becomes visible again when there is no flag image to stand in.
            'text_visible'  => $options['label_format'] !== 'none' || $flag === '',
            'flag_url'      => $flag,
            'flag_position' => $options['flag_position'],
        ];
    }

    /**
     * "FR", or "PT-BR" when another listed locale shares the language "PT".
     *
     * @param array<string, mixed>             $row
     * @param array<int, array<string, mixed>> $rows
     */
    private function shortName(array $row, array $rows): string
    {
        $language = strtoupper(explode('_', (string) $row['code'])[0]);
        $region   = (string) ($row['region'] ?? '');

        foreach ($rows as $other) {
            if ($other['code'] !== $row['code'] && strtoupper(explode('_', (string) $other['code'])[0]) === $language) {
                return $region !== '' ? $language . '-' . $region : $language;
            }
        }

        return $language;
    }

    private function templatePath(): string
    {
        $override = locate_template('lingowp/switcher.php');

        return $override !== '' ? $override : __DIR__ . '/templates/switcher.php';
    }

    /**
     * @param mixed    $value
     * @param string[] $allowed First entry is the default.
     */
    private static function pick($value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? (string) $value : $allowed[0];
    }
}

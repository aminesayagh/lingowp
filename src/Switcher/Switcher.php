<?php

namespace LingoWP\Switcher;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Domain\FlagResolver;
use LingoWP\Language\Infrastructure\WordPressLanguageCatalog;
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
    private const NAME_SOURCES   = ['native', 'english', 'current'];
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
        add_action('wp_head', [$this, 'printJsFlag'], 1);
        add_filter('script_loader_tag', [$this, 'excludeFromRocketLoader'], 10, 2);
        add_filter('pre_render_block', [$this, 'skipAutopForRenderedSwitcher'], 10, 2);
        // Before wp_print_footer_scripts (20), so the script it enqueues still prints.
        add_action('wp_footer', [$this, 'renderFloating'], 5);
    }

    /**
     * @param array<string, mixed> $args See normalize().
     */
    public function render(array $args = []): string
    {
        $options = $this->normalize($args);

        // A floating switcher is always a fit-width dropdown: a full-width bar
        // across a screen corner would cover the page.
        if ($options['floating'] !== '') {
            $options['layout']       = 'dropdown';
            $options['button_width'] = 'fit';
        }

        // hide_current is applied here, not in LanguageLinks: the dropdown
        // trigger still has to show the active language after it is removed
        // from the choices.
        // order="alpha" is applied below, to the labels the visitor reads —
        // LanguageLinks' own 'alpha' sorts by full native name before any label
        // exists (and ignores order entirely when include is given).
        $alpha = $options['order'] === 'alpha';
        $rows  = $this->links->links([
            'include' => $options['include'],
            'order'   => $alpha ? 'settings' : $options['order'],
        ]);

        if ($rows === []) {
            return '';
        }

        // The page's language, looked up among ALL enabled languages: include
        // may leave it out of the choices, but it is still what the dropdown
        // button shows and the language name_source="current" writes in.
        // (Cheap: LanguageLinks memoizes the base rows per request.)
        $current = $rows[0];
        foreach ($this->links->links() as $row) {
            if (!empty($row['current'])) {
                $current = $row;
                break;
            }
        }

        // name_source="current": every name is written in the page's
        // language, and its tag/direction go on the label text.
        $options['display_locale'] = (string) $current['code'];
        $options['display_dir']    = (string) $current['dir'];

        // Square (1x1) flags once the effective flag radius is above 8px — the
        // saved design plus this switcher's own override.
        $options['square_flags'] = SwitcherSettings::squareFlags($options['design'] + SwitcherSettings::get()['design']);

        $choices = $options['hide_current']
            ? array_filter($rows, static fn(array $row): bool => empty($row['current']))
            : $rows;

        // hide_current (or an include of only the current language) can leave
        // nothing to choose — render nothing rather than an empty dropdown.
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

        // Whether the switcher shows a box (border or background), from the
        // saved design plus this switcher's own overrides — CSS cannot test
        // whether a variable is set. Drives padding and the full-width button.
        $boxed = SwitcherSettings::isBoxed($options['design'] + SwitcherSettings::get()['design'])
            // Floating over page content: always a box (its own default
            // background in CSS when none is set).
            || $options['floating'] !== '';

        // Same option ResolveRequestLanguage reads before it writes the cookie:
        // when the admin turns the preference cookie off, the script must not
        // write it either.
        $cookie = (bool) get_option('lingowp_cookie_preference', true) ? ResolveRequestLanguage::COOKIE_NAME : '';

        $switcher = [
            'id'      => $id,
            'list_id' => $id . '-list',
            'layout'  => $options['layout'],
            'classes' => trim(
                'lingowp-switcher lingowp-switcher--' . $options['layout']
                . ' lingowp-switcher--' . $options['size']
                . ($boxed ? ' lingowp-switcher--boxed' : '')
                // A full-width button only differs visibly when it has a box.
                . ($options['layout'] === 'dropdown' && $boxed && $options['button_width'] === 'full' ? ' lingowp-switcher--full' : '')
                . ($options['floating'] !== ''
                    ? ' lingowp-switcher--floating lingowp-switcher--floating-' . ($options['floating'] === 'bottom-left' ? 'left' : 'right')
                    : '')
                // Only a dropdown has a button for the arrow to sit in.
                . ($options['layout'] === 'dropdown' && $options['show_arrow'] ? ' lingowp-switcher--arrow' : '')
                . ($options['layout'] === 'dropdown' && $options['dropdown_animation'] ? ' lingowp-switcher--animate' : '')
                . ($options['layout'] === 'dropdown' && $options['dropdown_shadow'] !== 'none' ? ' lingowp-switcher--shadow-' . $options['dropdown_shadow'] : '')
                . ' ' . $options['class']
            ),
            // This switcher's own design overrides as CSS variables; the saved
            // site-wide values come from <head> (SwitcherSettings), and an
            // inline style on the nav wins over them.
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
     *   size = sm|md|lg                     -> same, now the boxed padding size
     *   shadow = false|true                 -> dropdown_shadow none | the default
     *   flag_fallback                       -> accepted, not used yet
     *
     * @param array<string, mixed> $args {
     *   @type string          $layout         'dropdown' (default) | 'horizontal' | 'vertical'.
     *   @type string          $label_format   'full' (default) | 'short' | 'none'.
     *   @type string          $name_source    'native' (default) | 'english' | 'current' — for full
     *                                         labels. 'current' writes every name in the language
     *                                         of the page being viewed ("Arabe" on a French page).
     *   @type string          $flag_position  'before' (default) | 'after' | 'hidden'.
     *   @type string          $open_on        'click' (default) | 'hover' — dropdown only.
     *   @type bool            $hide_current   Drop the active language from the choices.
     *   @type string[]        $include        Keep only these locales.
     *   @type string|string[] $order          'settings' (default) | 'alpha' | a locale list.
     *   @type string          $label          The nav's accessible name. Default "Language".
     *   @type string          $id             Wrapper id. Default generated.
     *   @type string          $class          Extra wrapper classes.
     *   @type string          $bg_color         Background color.
     *   @type string          $bg_hover_color   Background on hover / keyboard focus.
     *   @type string          $text_color       Text color.
     *   @type string          $text_hover_color Text on hover / keyboard focus.
     *   @type string          $border_color     Border color.
     *   @type string          $border_width     e.g. "1" or "1px" (px/em/rem, max 20px).
     *   @type string          $border_radius    e.g. "8px", "50%".
     *   @type string          $flag_radius      Flag corners: default 2px, "0" square corners,
     *                                           "50%" round. Above 8px the flags switch to
     *                                           their square (1x1) version.
     *   @type string          $dropdown_radius  Corners of the open dropdown list: default 4px.
     *   @type string          $button_gap       Space between flag and text on the dropdown
     *                                           button (also before its arrow): default 10px.
     *   @type string          $item_gap         Space between flag and text in each language
     *                                           item: default 10px.
     *   @type string          $size             'sm' | 'md' | 'lg': padding of the button and
     *                                           items once the switcher has a border, a
     *                                           background or a hover background. Default:
     *                                           the saved setting (md).
     *   @type string          $button_width     'fit' | 'full': dropdown button width once it
     *                                           has a border or background (full also makes
     *                                           the open list at least as wide). Default: saved
     *                                           setting (fit).
     *   @type string          $dropdown_shadow  'none' | 'sm' | 'md' | 'lg': shadow under the
     *                                           open dropdown list. Default: saved setting (md).
     *   @type bool|string     $dropdown_animation Fade/slide the list in on open and rotate the
     *                                           arrow smoothly (never with reduced motion).
     *                                           Default: the saved setting, which is off.
     *   @type bool|string     $show_arrow       Dropdown arrow on the button. Default: the
     *                                           saved setting, which is true unless changed.
     *                         The design args override the saved site-wide settings
     *                         (SwitcherSettings) for this switcher only. Colors: hex,
     *                         rgb(a), hsl(a) or a color name. Invalid values are dropped.
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
            // Per-switcher design overrides (bg_color, border_radius, …), validated
            // by the same rules as the saved settings; invalid values are dropped.
            'design'        => SwitcherSettings::sanitizeDesign($args),
            // Dropdown arrow: this switcher's own choice, else the saved setting
            // (shown unless a site owner turned it off).
            // Padding size for a boxed switcher: this switcher's own, else saved (md).
            'size'          => SwitcherSettings::validSize($args['size'] ?? null) ?? SwitcherSettings::get()['size'],
            // Shadow under the open dropdown list: this switcher's own, else the
            // previous switcher's shadow="false" (-> none), else saved (md).
            'dropdown_shadow' => SwitcherSettings::validShadow($args['dropdown_shadow'] ?? null)
                ?? (isset($args['shadow']) && $args['shadow'] !== '' && !filter_var($args['shadow'], FILTER_VALIDATE_BOOLEAN) ? 'none' : null)
                ?? SwitcherSettings::get()['dropdown_shadow'],
            // Dropdown button width (fit | full): own, else saved (fit).
            'button_width'  => SwitcherSettings::validButtonWidth($args['button_width'] ?? null) ?? SwitcherSettings::get()['button_width'],
            // Internal (renderFloating(), the admin preview) — not a shortcode
            // attribute: the floating switcher is a site setting.
            'floating'        => SwitcherSettings::validFloatingPosition($args['floating'] ?? null) ?? '',
            'floating_offset' => SwitcherSettings::sanitizeValue('floating_offset', $args['floating_offset'] ?? ''),
            // Open animation (and arrow rotation): own choice, else saved (off).
            'dropdown_animation' => isset($args['dropdown_animation']) && $args['dropdown_animation'] !== ''
                ? filter_var($args['dropdown_animation'], FILTER_VALIDATE_BOOLEAN)
                : SwitcherSettings::get()['dropdown_animation'],
            'show_arrow'    => isset($args['show_arrow']) && $args['show_arrow'] !== ''
                ? filter_var($args['show_arrow'], FILTER_VALIDATE_BOOLEAN)
                : SwitcherSettings::get()['show_arrow'],
        ];

        // Never hide both the text and the flag — the link would be empty.
        if ($options['label_format'] === 'none' && $options['flag_position'] === 'hidden') {
            $options['label_format'] = 'full';
        }

        return $options;
    }

    /**
     * The floating switcher: a dropdown fixed in a bottom corner of every
     * public page, when a site owner enabled it (saved settings: on/off,
     * corner, distance from the edges). Same design as every other switcher.
     */
    public function renderFloating(): void
    {
        $settings = SwitcherSettings::get();
        if (!$settings['floating_enabled']) {
            return;
        }

        echo $this->render([ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the switcher template escapes every value
            'floating'        => $settings['floating_position'],
            'floating_offset' => $settings['floating_offset'],
            'id'              => 'lingowp-floating-switcher',
            'label'           => __('Language', 'lingowp'),
        ]);
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

        // The stylesheet goes in <head> on every public page: a switcher can
        // render anywhere (shortcode, theme template, later a widget or menu),
        // and on classic themes a stylesheet enqueued at render time is printed
        // in the footer — after the list it styles has already painted.
        // ponytail: ~0.4 kB gzipped on pages without a switcher; switch to
        // per-surface detection if theming makes the file heavy.
        wp_enqueue_style('lingowp-switcher');
    }

    /**
     * Marks <html> with .lingowp-js before the first paint, so the stylesheet
     * can draw every dropdown closed from the start instead of as the full
     * no-JavaScript list (see switcher.css). Without JavaScript the class never
     * appears and the plain list stays.
     *
     * Safety net: if the switcher script never ran by the window load event
     * (blocked, 404, script error), the class is removed again so the links
     * show as the plain list rather than hiding behind a dead button.
     */
    public function printJsFlag(): void
    {
        wp_print_inline_script_tag(
            "(function(d,w){var c=d.documentElement.classList;c.add('lingowp-js');"
            . "w.addEventListener('load',function(){if(!w.lingowpSwitcher){c.remove('lingowp-js');}});}(document,window));",
            // Cloudflare Rocket Loader would otherwise defer this until after
            // the page paints, which is the flash this exists to prevent.
            ['data-cfasync' => 'false']
        );
    }

    /**
     * Keeps Rocket Loader from deferring the switcher script past the load
     * event, where printJsFlag()'s safety net would take it for a failure.
     * The script is small and already in the footer, so this costs nothing.
     *
     * @param string $tag
     * @param string $handle
     */
    public function excludeFromRocketLoader($tag, $handle): string
    {
        if ($handle !== 'lingowp-switcher') {
            return (string) $tag;
        }

        return str_replace('<script ', '<script data-cfasync="false" ', (string) $tag);
    }

    /**
     * Keeps a Shortcode block from running wpautop() over a switcher it holds.
     *
     * In template parts (a block theme's header or footer) WordPress expands
     * shortcodes BEFORE it renders blocks (_wp_apply_block_content_filters),
     * so the Shortcode block receives the switcher's finished HTML, and its
     * render callback — wpautop() — adds a <br> inside the button and every
     * row plus an empty <p> after the button: a taller header.
     *
     * When a Shortcode block already holds a rendered switcher, its content is
     * returned as-is. In post content the order is the reverse: the block still
     * holds "[lingowp_language_switcher]" when it renders, so this never
     * matches and core behaves as usual.
     *
     * @param string|null          $pre   Earlier short-circuit value, if any.
     * @param array<string, mixed> $block The parsed block.
     * @return string|null
     */
    public function skipAutopForRenderedSwitcher($pre, $block)
    {
        if ($pre !== null || ($block['blockName'] ?? '') !== 'core/shortcode') {
            return $pre;
        }

        $html = (string) ($block['innerHTML'] ?? '');

        return strpos($html, 'data-lingowp-switcher') !== false ? $html : null;
    }

    // ------------------------------------------------------------------

    /**
     * The stylesheet is already in <head> (registerAssets()); enqueuing it again
     * here is a no-op on the front end and covers renders outside it.
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
        $flag       = $options['flag_position'] === 'hidden' ? '' : (string) ($row['flag_url'] ?? '');
        if ($flag !== '' && !empty($options['square_flags'])) {
            // The square file when one is bundled; otherwise the 4x3 one, which
            // the template marks square too and CSS crops (object-fit).
            $flag = FlagResolver::url((string) $row['code'], '1x1') ?? $flag;
        }
        $showRegion = $this->showsRegion($row, $rows);
        $tag        = str_replace('_', '-', (string) $row['code']);

        // The label's own language and direction, which follow the words
        // actually written, not the language the link leads to: "Arabe" on a
        // French page is French text, "Arabic" is English text. Screen readers
        // pick their voice from lang, and dir keeps "(…)" from flipping.
        // Without the intl extension displayIn() cannot translate names and
        // returns the English one, so "current" degrades to plain "english" —
        // text and lang/dir together, never English words tagged as Arabic.
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

        // The full name's own language/direction, kept for the spoken text of a
        // short label (sr_text below) before the code takes over text_lang.
        $fullLang = $textLang;
        $fullDir  = $textDir;

        // Short names are codes ("AR", "FR-CA"), not words in any language:
        // they keep the target language's tag and direction.
        $short = $options['label_format'] === 'short';
        if ($short) {
            $textLang = $tag;
            $textDir  = (string) $row['dir'];
        }

        // Catalog names always carry the region as a trailing "(…)" —
        // "Français (Canada)" — so dropping it leaves the language alone. Only
        // stripped when the locale has a region, so a name that has no region
        // but happens to end in parentheses is left intact.
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
            // A hidden label stays in the markup for screen readers — and
            // becomes visible again when there is no flag image to stand in.
            'text_visible'  => $options['label_format'] !== 'none' || $flag === '',
            'text_lang'     => $textLang,
            'text_dir'      => $textDir,
            // A short code is shown on screen but spoken as
            // "FR-CA, Français (Canada)": the code alone reads as letters to a
            // screen reader, and keeping it in the spoken name lets voice-
            // control users say what they see (WCAG 2.5.3 Label in Name).
            // '' when the visible text is already what should be spoken.
            'sr_text'       => $short ? $text . ', ' . $full : '',
            'sr_lang'       => $fullLang,
            'sr_dir'        => $fullDir,
            'flag_url'      => $flag,
            'flag_square'   => $flag !== '' && !empty($options['square_flags']),
            'flag_position' => $options['flag_position'],
        ];
    }

    /**
     * Whether a label needs its region to tell languages apart. Only when
     * another listed locale shares the language AND the region is not the
     * language's own (region code = language code, like FR for fr):
     *
     *   en_US alone        -> English
     *   fr_FR + fr_CA      -> Français, Français (Canada)
     *   en_US + en_GB      -> English (United States), English (United Kingdom)
     *
     * "Listed" is every language this switcher shows, the current one included
     * even when hide_current drops it from the choices.
     *
     * @param array<string, mixed>             $row
     * @param array<int, array<string, mixed>> $rows
     */
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

    /**
     * "FR", or "FR-CA" when showsRegion() says the region is needed.
     *
     * @param array<string, mixed> $row
     */
    private function shortName(array $row, bool $showRegion): string
    {
        $language = strtoupper(self::languageOf($row));

        return $showRegion ? $language . '-' . strtoupper((string) $row['region']) : $language;
    }

    /**
     * "fr" for fr_FR.
     *
     * @param array<string, mixed> $row
     */
    private static function languageOf(array $row): string
    {
        return strtolower(explode('_', (string) $row['code'])[0]);
    }

    private function templatePath(): string
    {
        $override = locate_template('lingowp/switcher.php');

        return $override !== '' ? $override : __DIR__ . '/templates/switcher.php';
    }

    /**
     * order="alpha": items sorted by the label the visitor reads (the visible
     * name, or the screen-reader name of a flag-only item), the way a
     * dictionary in the page's language sorts it — "Íslenska" next to
     * "Italiano", not after "Z", and "Français" before "Français (Canada)".
     * Without the intl extension, an accent-insensitive comparison.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * @param mixed    $value
     * @param string[] $allowed First entry is the default.
     */
    private static function pick($value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? (string) $value : $allowed[0];
    }
}

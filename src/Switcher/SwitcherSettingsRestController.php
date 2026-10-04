<?php

namespace LingoWP\Switcher;

use LingoWP\Language\Domain\FlagResolver;
use LingoWP\Language\Infrastructure\WordPressLanguageCatalog;
use LingoWP\LocalizationRouting\LanguageLinks;

/**
 * lingowp/v1/switcher-settings — the saved switcher design, for the Account
 * page's "Language switcher" tab.
 *
 *   GET  /switcher-settings          the saved settings
 *   POST /switcher-settings          save (flat body, see below)
 *   POST /switcher-settings/preview  render the switcher with an UNSAVED draft
 *
 * POST bodies are flat: any design key (bg_color, border_radius, … — '' to
 * clear one), show_arrow, dropdown_animation, size (sm|md|lg), dropdown_shadow
 * (none|sm|md|lg), button_width (fit|full), floating_enabled, floating_position
 * (bottom-right|bottom-left), floating_offset, custom_css_enabled, custom_css.
 * Keys left out keep their saved value.
 *
 * Responses follow the plugin's convention (admin-ui/api.js): HTTP 200 with
 * { ok: true, … } or { ok: false, error: 'ERR_…', fields? }. An unacceptable
 * value is ERR_INVALID_INPUT naming the fields, so the form can mark them.
 *
 * Managing the site (manage_options) is enough for the design; custom CSS
 * additionally needs unfiltered_html — the same capability WordPress requires
 * to publish raw <script>/<style> in content.
 */
class SwitcherSettingsRestController
{
    private const NS = 'lingowp/v1';

    /** Layouts the preview can show. */
    private const PREVIEW_LAYOUTS = ['dropdown', 'horizontal', 'vertical'];

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NS, '/switcher-settings', ['methods' => 'GET', 'callback' => [$this, 'show'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/switcher-settings', ['methods' => 'POST', 'callback' => [$this, 'update'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(self::NS, '/switcher-settings/preview', ['methods' => 'POST', 'callback' => [$this, 'preview'], 'permission_callback' => [$this, 'canManage']]);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function show(): \WP_REST_Response
    {
        return new \WP_REST_Response(['ok' => true] + $this->payload(SwitcherSettings::get()));
    }

    public function update(\WP_REST_Request $request): \WP_REST_Response
    {
        $input      = $this->input($request);
        $canEditCss = current_user_can('unfiltered_html');

        if (!$canEditCss && (array_key_exists('custom_css', $input) || array_key_exists('custom_css_enabled', $input))) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'ERR_FORBIDDEN', 'fields' => ['custom_css']]);
        }

        $invalid = SwitcherSettings::invalidKeys($input);
        if ($invalid !== []) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT', 'fields' => $invalid]);
        }

        return new \WP_REST_Response(['ok' => true] + $this->payload(SwitcherSettings::update($input, $canEditCss)));
    }

    /**
     * The switcher rendered by the real template with an unsaved draft, as if
     * it were saved (SwitcherSettings::withDraft) — nothing is stored. Returns
     * the markup, the <head> CSS a page would carry, the stylesheet/script URLs
     * for the preview frame, and the draft fields that are not valid (they
     * are left out of the render, exactly as they would be on save).
     *
     * Body: the draft (same flat shape as POST /switcher-settings) plus
     * `layout` (dropdown | horizontal | vertical). Without the custom-CSS
     * capability the saved custom CSS is shown, never a draft one.
     */
    public function preview(\WP_REST_Request $request): \WP_REST_Response
    {
        $input  = $this->input($request);
        $layout = in_array($input['layout'] ?? '', self::PREVIEW_LAYOUTS, true) ? (string) $input['layout'] : 'dropdown';
        unset($input['layout']);

        $draft = SwitcherSettings::merge($input, current_user_can('unfiltered_html'));

        [$html, $floatingHtml, $headCss] = SwitcherSettings::withDraft($draft, function () use ($layout, $draft): array {
            $switcher = new Switcher($this->previewLinks());
            $html     = $switcher->render(['layout' => $layout, 'id' => 'lingowp-preview']);

            // The floating one too, in its corner of the preview frame.
            $floating = $draft['floating_enabled']
                ? $switcher->render([
                    'floating'        => $draft['floating_position'],
                    'floating_offset' => $draft['floating_offset'],
                    'id'              => 'lingowp-preview-floating',
                ])
                : '';

            ob_start();
            SwitcherSettings::printHeadStyles();

            return [$html, $floating, (string) ob_get_clean()];
        });

        return new \WP_REST_Response([
            'ok'       => true,
            'html'     => $html,
            // '' unless the draft enables the floating switcher.
            'floating_html' => $floatingHtml,
            'head_css' => $headCss,
            'invalid'  => SwitcherSettings::invalidKeys($input),
            'assets'   => [
                'css' => $this->assetUrl('switcher.css'),
                'js'  => $this->assetUrl('switcher.js'),
            ],
        ]);
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function input(\WP_REST_Request $request): array
    {
        return (array) $request->get_json_params() + (array) $request->get_body_params();
    }

    /**
     * The site's real languages, or — with fewer than two enabled, when the
     * switcher would render nothing — three sample languages, so the preview
     * always has something to show.
     */
    private function previewLinks(): LanguageLinks
    {
        $links = LanguageLinks::instance();
        if (count($links->links()) >= 2) {
            return $links;
        }

        $rows = [];
        foreach (['en_US', 'fr_FR', 'es_ES'] as $i => $code) {
            $rows[] = [
                'code'         => $code,
                'slug'         => strtolower(substr($code, 0, 2)),
                'url'          => '#',
                'native_name'  => WordPressLanguageCatalog::nativeDisplay($code, $code),
                'english_name' => WordPressLanguageCatalog::displayIn($code, 'en_US', $code),
                'dir'          => 'ltr',
                'region'       => substr($code, 3, 2),
                'flag_url'     => FlagResolver::url($code) ?? '',
                'is_source'    => $i === 0,
                'current'      => $i === 0,
            ];
        }

        // Fixed rows instead of the request-derived ones.
        return new class($rows) extends LanguageLinks {
            /** @var array<int, array<string, mixed>> */
            private array $sampleRows;

            /** @param array<int, array<string, mixed>> $rows */
            public function __construct(array $rows)
            {
                $this->sampleRows = $rows;
            }

            public function links(array $args = []): array
            {
                return $this->sampleRows;
            }
        };
    }

    private function assetUrl(string $file): string
    {
        $path = LINGOWP_DIR . 'assets/switcher/build/' . $file;

        return LINGOWP_URL . 'assets/switcher/build/' . $file . (file_exists($path) ? '?ver=' . filemtime($path) : '');
    }

    /**
     * Flat shape (the same one POST accepts), plus whether this user may edit
     * the custom CSS, so the form can hide that field.
     *
     * @param array{version:int, design:array<string,string>, show_arrow:bool, dropdown_animation:bool, size:string, dropdown_shadow:string, button_width:string, floating_enabled:bool, floating_position:string, floating_offset:string, custom_css_enabled:bool, custom_css:string} $settings
     * @return array<string, mixed>
     */
    private function payload(array $settings): array
    {
        $out = ['version' => $settings['version']];
        foreach (array_keys(SwitcherSettings::DESIGN_KEYS) as $key) {
            $out[$key] = $settings['design'][$key] ?? '';
        }
        $out['show_arrow']         = $settings['show_arrow'];
        $out['dropdown_animation'] = $settings['dropdown_animation'];
        $out['size']               = $settings['size'];
        $out['dropdown_shadow']    = $settings['dropdown_shadow'];
        $out['button_width']       = $settings['button_width'];
        $out['floating_enabled']   = $settings['floating_enabled'];
        $out['floating_position']  = $settings['floating_position'];
        $out['floating_offset']    = $settings['floating_offset'];
        $out['custom_css_enabled'] = $settings['custom_css_enabled'];
        $out['custom_css']         = $settings['custom_css'];
        $out['can_edit_css']       = current_user_can('unfiltered_html');

        return $out;
    }
}

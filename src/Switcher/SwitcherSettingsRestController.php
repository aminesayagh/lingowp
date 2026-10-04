<?php

namespace LingoWP\Switcher;

use LingoWP\Language\Domain\FlagResolver;
use LingoWP\Language\Infrastructure\WordPressLanguageCatalog;
use LingoWP\LocalizationRouting\LanguageLinks;

class SwitcherSettingsRestController
{
    private const NS = 'lingowp/v1';

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

    public function preview(\WP_REST_Request $request): \WP_REST_Response
    {
        $input  = $this->input($request);
        $layout = in_array($input['layout'] ?? '', self::PREVIEW_LAYOUTS, true) ? (string) $input['layout'] : 'dropdown';
        unset($input['layout']);

        $draft = SwitcherSettings::merge($input, current_user_can('unfiltered_html'));

        [$html, $floatingHtml, $headCss] = SwitcherSettings::withDraft($draft, function () use ($layout, $draft): array {
            $switcher = new Switcher($this->previewLinks());
            $html     = $switcher->render(['layout' => $layout, 'id' => 'lingowp-preview']);

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
            'floating_html' => $floatingHtml,
            'head_css' => $headCss,
            'invalid'  => SwitcherSettings::invalidKeys($input),
            'assets'   => [
                'css' => $this->assetUrl('switcher.css'),
                'js'  => $this->assetUrl('switcher.js'),
            ],
        ]);
    }

    private function input(\WP_REST_Request $request): array
    {
        return (array) $request->get_json_params() + (array) $request->get_body_params();
    }

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

        return new class($rows) extends LanguageLinks {
            private array $sampleRows;

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

<?php

namespace LingoWP\LocalizationRouting;

/**
 * Registers [lingowp_language_switcher]. All rendering lives in SwitcherRenderer;
 * this only maps shortcode attributes onto the renderer's args.
 *
 *   [lingowp_language_switcher display="english" layout="inline" hide_current="true"]
 *
 * `show` is accepted as a legacy alias for `display`.
 */
class LanguageSwitcherShortcode
{
    public const SHORTCODE = 'lingowp_language_switcher';

    private SwitcherRenderer $renderer;

    public function __construct(SwitcherRenderer $renderer)
    {
        $this->renderer = $renderer;
    }

    public function register(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'shortcode']);
    }

    /**
     * @param array<string, string>|string $atts
     */
    public function shortcode($atts = []): string
    {
        $atts = shortcode_atts([
            'display'      => 'native',
            'show'         => '', // legacy alias for display
            'layout'       => 'dropdown',
            'hide_current' => 'false',
            'class'        => '',
        ], (array) $atts, self::SHORTCODE);

        return $this->renderer->render([
            'display'      => $atts['show'] !== '' ? $atts['show'] : $atts['display'],
            'layout'       => $atts['layout'],
            'hide_current' => filter_var($atts['hide_current'], FILTER_VALIDATE_BOOLEAN),
            'class'        => $atts['class'],
        ]);
    }

    /**
     * @deprecated Use lingowp_get_switcher(). Kept for themes that called the
     *             old render() method directly.
     */
    public function render(string $show = 'native'): string
    {
        return $this->renderer->render(['display' => $show]);
    }
}

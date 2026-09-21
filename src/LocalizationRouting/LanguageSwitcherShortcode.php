<?php

namespace LingoWP\LocalizationRouting;

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

    public function shortcode($atts = []): string
    {
        $atts = shortcode_atts([
            'display'       => 'native',
            'show'          => '',
            'layout'        => 'dropdown',
            'flags'         => '',
            'flag_fallback' => 'globe',
            'size'          => 'lg',
            'shadow'        => 'true',
            'hide_current'  => 'false',
            'label'         => '',
            'id'            => '',
            'class'         => '',
            'include'       => '',
            'order'         => '',
        ], (array) $atts, self::SHORTCODE);

        return $this->renderer->render([
            'display'       => $atts['show'] !== '' ? $atts['show'] : $atts['display'],
            'layout'        => $atts['layout'],
            'flags'         => $atts['flags'] === '' ? null : $atts['flags'],
            'flag_fallback' => $atts['flag_fallback'],
            'size'          => $atts['size'],
            'shadow'        => filter_var($atts['shadow'], FILTER_VALIDATE_BOOLEAN),
            'hide_current'  => filter_var($atts['hide_current'], FILTER_VALIDATE_BOOLEAN),
            'label'         => $atts['label'],
            'id'            => $atts['id'],
            'class'         => $atts['class'],
            'include'       => $this->csvList($atts['include']),
            'order'         => $this->orderArg($atts['order']),
        ]);
    }

    public function render(string $show = 'native'): string
    {
        return $this->renderer->render(['display' => $show]);
    }

    private function csvList(string $csv): ?array
    {
        $items = array_values(array_filter(array_map('trim', explode(',', $csv)), 'strlen'));

        return $items === [] ? null : $items;
    }

    private function orderArg(string $value)
    {
        $value = trim($value);
        if ($value === '' || $value === 'settings' || $value === 'alpha') {
            return $value === '' ? 'settings' : $value;
        }

        return $this->csvList($value) ?? 'settings';
    }
}

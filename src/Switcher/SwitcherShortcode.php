<?php

namespace LingoWP\Switcher;

class SwitcherShortcode
{
    public const TAG = 'lingowp_language_switcher';

    private const ATTRIBUTES = [
        'layout', 'label_format', 'name_source', 'flag_position', 'open_on',
        'hide_current', 'include', 'order', 'label', 'id', 'class',
        'display', 'show', 'flags', 'size', 'shadow', 'flag_fallback',
        'bg_color', 'bg_hover_color', 'text_color', 'text_hover_color',
        'border_color', 'border_width', 'border_radius', 'flag_radius', 'dropdown_radius', 'button_gap', 'item_gap', 'show_arrow', 'dropdown_shadow', 'button_width', 'dropdown_animation',
    ];

    private Switcher $switcher;

    public function __construct(Switcher $switcher)
    {
        $this->switcher = $switcher;
    }

    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'shortcode']);
    }

    public function shortcode($atts = []): string
    {
        $atts = shortcode_atts(array_fill_keys(self::ATTRIBUTES, null), (array) $atts, self::TAG);
        $args = array_map(static fn($value): string => trim((string) $value), array_filter($atts, static fn($value): bool => $value !== null));

        if (isset($args['include'])) {
            $args['include'] = $this->csvList($args['include']);
        }
        if (isset($args['order'])) {
            $args['order'] = in_array($args['order'], ['', 'settings', 'alpha'], true)
                ? ($args['order'] === '' ? 'settings' : $args['order'])
                : ($this->csvList($args['order']) ?? 'settings');
        }

        return $this->switcher->render($args);
    }

    private function csvList(string $csv): ?array
    {
        $items = array_values(array_filter(array_map('trim', explode(',', $csv)), 'strlen'));

        return $items === [] ? null : $items;
    }
}

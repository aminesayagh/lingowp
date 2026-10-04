<?php

namespace LingoWP\Switcher;

/**
 * [lingowp_language_switcher] — same tag as the previous switcher, so pages that
 * already use it switch over without edits. Turns attribute text into
 * Switcher::render() options; old attributes (display, show, flags,
 * layout="inline|list") are mapped inside Switcher::normalize().
 *
 *   [lingowp_language_switcher layout="horizontal" label_format="short"]
 *   [lingowp_language_switcher flag_position="after" name_source="english" open_on="hover"]
 *   [lingowp_language_switcher include="fr_FR,es_ES" order="alpha" hide_current="true"]
 *   [lingowp_language_switcher name_source="current"]  ("Arabe" on a French page)
 *   [lingowp_language_switcher bg_color="#111" text_color="#fff" border_radius="8" flag_radius="50%"]
 *   [lingowp_language_switcher show_arrow="false"]  (dropdown without its arrow)
 *   [lingowp_language_switcher border_width="1" size="sm"]  (boxed, compact padding)
 *   [lingowp_language_switcher dropdown_shadow="lg"]  (none | sm | md | lg)
 *   [lingowp_language_switcher bg_color="#eee" button_width="full"]  (fit | full, needs a box)
 *   [lingowp_language_switcher dropdown_animation="true"]  (off by default)
 *
 * Design attributes override the saved site-wide settings for this switcher
 * only. Custom CSS is deliberately NOT an attribute: any post author can write
 * shortcodes, so it lives in the administrator-only settings.
 */
class SwitcherShortcode
{
    public const TAG = 'lingowp_language_switcher';

    /** Every attribute the switcher understands, new and legacy. */
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

    /**
     * @param array<string, string>|string $atts
     */
    public function shortcode($atts = []): string
    {
        // null defaults, then dropped: an attribute the author did not write
        // falls through to the switcher's own default and legacy mapping
        // instead of being pinned to a value here.
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

    /**
     * "fr_FR, es_ES" -> ['fr_FR', 'es_ES']; "" -> null.
     *
     * @return string[]|null
     */
    private function csvList(string $csv): ?array
    {
        $items = array_values(array_filter(array_map('trim', explode(',', $csv)), 'strlen'));

        return $items === [] ? null : $items;
    }
}

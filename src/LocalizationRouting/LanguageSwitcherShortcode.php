<?php

namespace LingoWP\LocalizationRouting;

/**
 * Registers [lingowp_language_switcher]. All rendering lives in SwitcherRenderer;
 * this only maps shortcode attributes onto the renderer's args.
 *
 *   [lingowp_language_switcher display="english" layout="inline" hide_current="true"]
 *   [lingowp_language_switcher flags="only" flag_fallback="code" label="Idioma"]
 *   [lingowp_language_switcher include="fr_FR,es_ES" order="alpha"]
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
            'display'       => 'native',
            'show'          => '', // legacy alias for display
            'layout'        => 'dropdown',
            'flags'         => '',      // '' = layout default; true | false | only
            'flag_fallback' => 'globe', // globe | code | none
            'size'          => 'lg',    // lg | md | sm
            'shadow'        => 'true',
            'hide_current'  => 'false',
            'label'         => '',
            'id'            => '',
            'class'         => '',
            'include'       => '', // comma-separated locale list
            'order'         => '', // 'settings' | 'alpha' | comma-separated locale list
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

    /**
     * @deprecated Use lingowp_get_switcher(). Kept for themes that called the
     *             old render() method directly.
     */
    public function render(string $show = 'native'): string
    {
        return $this->renderer->render(['display' => $show]);
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

    /**
     * "" -> 'settings'; a keyword -> itself; a locale list -> array.
     *
     * @return string|string[]
     */
    private function orderArg(string $value)
    {
        $value = trim($value);
        if ($value === '' || $value === 'settings' || $value === 'alpha') {
            return $value === '' ? 'settings' : $value;
        }

        return $this->csvList($value) ?? 'settings';
    }
}

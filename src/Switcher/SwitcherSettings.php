<?php

namespace LingoWP\Switcher;

class SwitcherSettings
{
    public const OPTION  = 'lingowp_switcher_settings';
    public const VERSION = 1;

    public const DESIGN_KEYS = [
        'bg_color'         => ['--lingowp-switcher-bg', 'color'],
        'bg_hover_color'   => ['--lingowp-switcher-bg-hover', 'color'],
        'text_color'       => ['--lingowp-switcher-text', 'color'],
        'text_hover_color' => ['--lingowp-switcher-text-hover', 'color'],
        'border_color'     => ['--lingowp-switcher-border-color', 'color'],
        'border_width'     => ['--lingowp-switcher-border-width', 'width'],
        'border_radius'    => ['--lingowp-switcher-radius', 'radius'],
        'flag_radius'      => ['--lingowp-switcher-flag-radius', 'radius'],
        'dropdown_radius'  => ['--lingowp-switcher-dropdown-radius', 'radius'],
        'button_gap'       => ['--lingowp-switcher-button-gap', 'gap'],
        'item_gap'         => ['--lingowp-switcher-item-gap', 'gap'],
    ];

    public const SIZES = ['md', 'sm', 'lg'];

    public const SHADOWS = ['md', 'none', 'sm', 'lg'];

    public const BUTTON_WIDTHS = ['fit', 'full'];

    public const FLOATING_POSITIONS = ['bottom-right', 'bottom-left'];

    private const OTHER_LENGTHS = ['floating_offset' => 'gap'];

    private const MAX_WIDTH = ['px' => 20, 'em' => 2, 'rem' => 2];

    private const MAX_GAP = ['px' => 100, 'em' => 5, 'rem' => 5];

    public static function get(): array
    {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];

        return [
            'version'            => self::VERSION,
            'design'             => self::sanitizeDesign(is_array($saved['design'] ?? null) ? $saved['design'] : []),
            'show_arrow'         => !array_key_exists('show_arrow', $saved) || !empty($saved['show_arrow']),
            'dropdown_animation' => !empty($saved['dropdown_animation']),
            'size'               => self::validSize($saved['size'] ?? null) ?? self::SIZES[0],
            'dropdown_shadow'    => self::validShadow($saved['dropdown_shadow'] ?? null) ?? self::SHADOWS[0],
            'button_width'       => self::validButtonWidth($saved['button_width'] ?? null) ?? self::BUTTON_WIDTHS[0],
            'floating_enabled'   => !empty($saved['floating_enabled']),
            'floating_position'  => self::validFloatingPosition($saved['floating_position'] ?? null) ?? self::FLOATING_POSITIONS[0],
            'floating_offset'    => self::sanitizeValue('floating_offset', $saved['floating_offset'] ?? ''),
            'custom_css_enabled' => !empty($saved['custom_css_enabled']),
            'custom_css'         => (string) ($saved['custom_css'] ?? ''),
        ];
    }

    public static function merge(array $input, bool $canEditCss): array
    {
        $current = self::get();

        $design = $current['design'];
        foreach (array_keys(self::DESIGN_KEYS) as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = self::sanitizeValue($key, $input[$key]);
            if ($value === '') {
                unset($design[$key]);
            } else {
                $design[$key] = $value;
            }
        }

        $stored = [
            'version'            => self::VERSION,
            'design'             => $design,
            'show_arrow'         => array_key_exists('show_arrow', $input)
                ? filter_var($input['show_arrow'], FILTER_VALIDATE_BOOLEAN)
                : $current['show_arrow'],
            'dropdown_animation' => array_key_exists('dropdown_animation', $input)
                ? filter_var($input['dropdown_animation'], FILTER_VALIDATE_BOOLEAN)
                : $current['dropdown_animation'],
            'size'               => self::validSize($input['size'] ?? null) ?? $current['size'],
            'dropdown_shadow'    => self::validShadow($input['dropdown_shadow'] ?? null) ?? $current['dropdown_shadow'],
            'button_width'       => self::validButtonWidth($input['button_width'] ?? null) ?? $current['button_width'],
            'floating_enabled'   => array_key_exists('floating_enabled', $input)
                ? filter_var($input['floating_enabled'], FILTER_VALIDATE_BOOLEAN)
                : $current['floating_enabled'],
            'floating_position'  => self::validFloatingPosition($input['floating_position'] ?? null) ?? $current['floating_position'],
            'floating_offset'    => array_key_exists('floating_offset', $input)
                ? self::sanitizeValue('floating_offset', $input['floating_offset'])
                : $current['floating_offset'],
            'custom_css_enabled' => $current['custom_css_enabled'],
            'custom_css'         => $current['custom_css'],
        ];

        if ($canEditCss) {
            if (array_key_exists('custom_css_enabled', $input)) {
                $stored['custom_css_enabled'] = filter_var($input['custom_css_enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            if (array_key_exists('custom_css', $input)) {
                $stored['custom_css'] = (string) $input['custom_css'];
            }
        }

        return $stored;
    }

    public static function update(array $input, bool $canEditCss): array
    {
        update_option(self::OPTION, self::merge($input, $canEditCss));

        return self::get();
    }

    public static function withDraft(array $stored, callable $render)
    {
        $filter = static fn() => $stored;
        add_filter('pre_option_' . self::OPTION, $filter);

        try {
            return $render();
        } finally {
            remove_filter('pre_option_' . self::OPTION, $filter);
        }
    }

    public static function invalidKeys(array $input): array
    {
        $invalid = [];
        foreach (array_keys(self::DESIGN_KEYS) as $key) {
            if (isset($input[$key]) && trim((string) $input[$key]) !== '' && self::sanitizeValue($key, $input[$key]) === '') {
                $invalid[] = $key;
            }
        }

        if (isset($input['size']) && trim((string) $input['size']) !== '' && self::validSize($input['size']) === null) {
            $invalid[] = 'size';
        }
        if (isset($input['dropdown_shadow']) && trim((string) $input['dropdown_shadow']) !== '' && self::validShadow($input['dropdown_shadow']) === null) {
            $invalid[] = 'dropdown_shadow';
        }
        if (isset($input['button_width']) && trim((string) $input['button_width']) !== '' && self::validButtonWidth($input['button_width']) === null) {
            $invalid[] = 'button_width';
        }
        if (isset($input['floating_position']) && trim((string) $input['floating_position']) !== '' && self::validFloatingPosition($input['floating_position']) === null) {
            $invalid[] = 'floating_position';
        }
        if (isset($input['floating_offset']) && trim((string) $input['floating_offset']) !== '' && self::sanitizeValue('floating_offset', $input['floating_offset']) === '') {
            $invalid[] = 'floating_offset';
        }

        return $invalid;
    }

    public static function validSize($value): ?string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return in_array($value, self::SIZES, true) ? $value : null;
    }

    public static function validShadow($value): ?string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return in_array($value, self::SHADOWS, true) ? $value : null;
    }

    public static function validFloatingPosition($value): ?string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return in_array($value, self::FLOATING_POSITIONS, true) ? $value : null;
    }

    public static function validButtonWidth($value): ?string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return in_array($value, self::BUTTON_WIDTHS, true) ? $value : null;
    }

    private const SQUARE_FLAG_ABOVE_PX = 8;

    public static function squareFlags(array $design): bool
    {
        if (!preg_match('/^(\d+(?:\.\d+)?)(px|em|rem|%)$/', (string) ($design['flag_radius'] ?? ''), $m)) {
            return false;
        }

        $number = (float) $m[1];
        $px     = $m[2] === 'px' ? $number : ($m[2] === '%' ? $number / 100 * 20 : $number * 16);

        return $px > self::SQUARE_FLAG_ABOVE_PX;
    }

    public static function isBoxed(array $design): bool
    {
        $border = (float) ($design['border_width'] ?? '0');
        if ($border > 0) {
            return true;
        }

        foreach (['bg_color', 'bg_hover_color'] as $key) {
            $color = (string) ($design[$key] ?? '');
            if ($color !== '' && $color !== 'transparent') {
                return true;
            }
        }

        return false;
    }

    public static function sanitizeDesign(array $values): array
    {
        $out = [];
        foreach (array_keys(self::DESIGN_KEYS) as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $value = self::sanitizeValue($key, $values[$key]);
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public static function cssVariables(array $design): string
    {
        $declarations = [];
        foreach (self::DESIGN_KEYS as $key => [$variable]) {
            if (($design[$key] ?? '') !== '') {
                $declarations[] = $variable . ':' . $design[$key];
            }
        }

        return implode(';', $declarations);
    }

    public static function register(): void
    {
        add_action('wp_head', [self::class, 'printHeadStyles'], 100);
    }

    public static function printHeadStyles(): void
    {
        $settings  = self::get();
        $variables = self::cssVariables($settings['design']);

        if ($variables !== '') {
            echo '<style id="lingowp-switcher-settings">.lingowp-switcher{' . $variables . "}</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- allow-listed CSS values, see sanitizeValue()
        }

        if ($settings['custom_css_enabled'] && trim($settings['custom_css']) !== '') {
            echo '<style id="lingowp-switcher-custom-css">' . wp_strip_all_tags($settings['custom_css']) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS context; tags stripped like wp_custom_css_cb()
        }
    }

    public static function sanitizeValue(string $key, $value): string
    {
        $kind  = self::DESIGN_KEYS[$key][1] ?? (self::OTHER_LENGTHS[$key] ?? '');
        $value = strtolower(trim(is_scalar($value) ? (string) $value : ''));

        if ($value === '') {
            return '';
        }

        if ($kind === 'color') {
            $ok = (bool) preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value)
                || (bool) preg_match('#^(?:rgba?|hsla?)\(\s*[0-9.%,/\s]+\)$#', $value)
                || (bool) preg_match('/^[a-z]{3,20}$/', $value);

            return $ok ? $value : '';
        }

        if (!preg_match('/^(\d{1,4}(?:\.\d{1,3})?)(px|em|rem|%)?$/', $value, $m)) {
            return '';
        }

        $number = (float) $m[1];
        $unit   = $m[2] ?? 'px';
        $unit   = $unit === '' ? 'px' : $unit;

        if ($kind === 'width' || $kind === 'gap') {
            $max = $kind === 'width' ? self::MAX_WIDTH : self::MAX_GAP;
            if (!isset($max[$unit]) || $number > $max[$unit]) {
                return '';
            }
        } elseif ($unit === '%' && $number > 100) {
            return '';
        }

        return $m[1] . $unit;
    }
}

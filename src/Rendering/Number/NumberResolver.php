<?php

namespace TransLangs\Rendering\Number;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Rendering\RequestContext;

/**
 * Localizes WordPress's and WooCommerce's own locale-aware number/price
 * formatting to the visitor's target language on a translated request.
 *
 * number_format_i18n() (wp-includes/functions.php) and WooCommerce's
 * wc_price() (via its formatted_woocommerce_price filter) both format using
 * the SITE's own current locale/settings — $wp_locale->number_format for the
 * former, the store's configured decimal/thousand separators for the latter
 * — never the visitor's target language. Same bug shape DateResolver already
 * fixed for dates: the incoming pre-formatted value is ignored entirely and
 * recomputed fresh from the raw number, because the incoming value is
 * already formatted to the source convention, not reliably anything safe to
 * search-and-replace on.
 *
 * One shared method handles both filters: the underlying operation (format
 * a raw number, at a given decimal precision, in a given locale) is
 * identical either way, and both filters share the same
 * ($value, $number, $decimals, ...) argument shape in their first three
 * positions. formatted_woocommerce_price fires on the bare number, before
 * wc_price() wraps it in a currency-symbol span — hooking there localizes
 * only the number, leaving currency symbol/position (a store setting, not a
 * language concern) untouched. No WooCommerce-active guard is needed: if
 * WooCommerce isn't installed, that filter simply never fires — registering
 * a callback for it is free, the same reasoning already used for the
 * unconditional _wc_product_short_description entry in TranslatableMetaKeys.
 *
 * Two ICU behaviors are pinned explicitly rather than left at their
 * defaults, both verified directly before relying on them:
 *   - `@numbers=latn` on every locale: some Arabic-region locales (e.g.
 *     ar_SA) default to Eastern Arabic-Indic digits ("١٬٢٣٤٫٥٠") rather than
 *     the Western digits WordPress sites overwhelmingly expect — the same
 *     class of silent-wrong-convention risk DateResolver avoids by pinning
 *     IntlDateFormatter::GREGORIAN explicitly.
 *   - `ROUNDING_MODE => ROUND_HALFUP`: NumberFormatter's default is
 *     round-half-EVEN (banker's rounding), which silently disagrees with
 *     PHP's own number_format()/round() (round-half-away-from-zero) on
 *     boundary values — e.g. 1.005 rounds to 1.01 via number_format() but
 *     1.00 via a default-configured NumberFormatter. Without pinning this, a
 *     price landing exactly on a rounding boundary could display a
 *     different value in the target language than in the source.
 *
 * Known, accepted limitation: WooCommerce's wc_trim_zeros() (only invoked
 * when the non-default woocommerce_price_trim_zeros filter is enabled)
 * searches for the STORE's configured decimal separator specifically
 * (wc-formatting-functions.php) — once this resolver has rewritten the
 * separator to the target locale's own convention, that search can silently
 * fail to match, so a translated page may show trailing zeros a store
 * configured to trim elsewhere. Never produces a wrong price value, only a
 * cosmetic difference, and only for sites using both WooCommerce and that
 * non-default option — not worth engineering around for that combination.
 *
 * Deliberately does not add number_format_i18n to CrawlSkipHooks: PHP's
 * number_format() (what backs it) only ever emits ASCII 0-9 regardless of
 * locale, and @numbers=latn forces the same on this resolver's output, so a
 * bare number is already excluded from tl_html by TranslatableText's
 * no-letter rule end-to-end, on both the pass-through and reformatted paths.
 */
final class NumberResolver
{
    private ?bool $active = null;
    private bool $activating = false;
    private string $lang = '';

    /** @var array<string, \NumberFormatter|null> "lang|decimals" => formatter, memoized per request */
    private array $formatters = [];

    public function __construct(
        private ResolveRequestLanguage $language,
        private RequestContext $context
    ) {
    }

    public function register(): void
    {
        add_filter('number_format_i18n', [$this, 'reformat'], 10, 3);
        add_filter('formatted_woocommerce_price', [$this, 'reformat'], 10, 3);
    }

    /**
     * @param mixed $value
     * @param mixed $number
     * @param mixed $decimals
     * @return mixed
     */
    public function reformat($value, $number, $decimals)
    {
        if (!is_string($value) || $value === '' || !is_numeric($number)) {
            return $value;
        }
        if (!$this->isActive() || !class_exists(\NumberFormatter::class)) {
            return $value;
        }

        try {
            $formatter = $this->formatterFor((int) $decimals);
            if ($formatter === null) {
                return $value;
            }

            $formatted = $formatter->format((float) $number);

            return $formatted === false ? $value : $formatted;
        } catch (\Throwable) {
            return $value;
        }
    }

    private function formatterFor(int $decimals): ?\NumberFormatter
    {
        $key = $this->lang . '|' . $decimals;
        if (array_key_exists($key, $this->formatters)) {
            return $this->formatters[$key];
        }

        try {
            $formatter = new \NumberFormatter($this->lang . '@numbers=latn', \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(\NumberFormatter::ROUNDING_MODE, \NumberFormatter::ROUND_HALFUP);
        } catch (\Throwable) {
            $formatter = null;
        }

        return $this->formatters[$key] = $formatter;
    }

    /**
     * Deferred, memoized verdict — copied from DateResolver::isActive() /
     * OptionResolver::isActive(). shouldTranslate()/isTranslatedRequest()
     * read conditional query tags that don't exist before the `wp` action
     * fires, so a verdict computed any earlier would wrongly memoize
     * "untranslated" for the whole request.
     */
    private function isActive(): bool
    {
        if ($this->active !== null) {
            return $this->active;
        }
        if (!did_action('wp') || $this->activating) {
            return false;
        }

        $this->activating = true;
        try {
            $active = $this->context->shouldTranslate() && $this->language->isTranslatedRequest();
        } finally {
            $this->activating = false;
        }

        $this->active = $active;
        if ($active) {
            $this->lang = $this->language->resolve();
        }

        return $this->active;
    }
}

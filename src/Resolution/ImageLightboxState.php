<?php

namespace LingoWP\Resolution;

/**
 * Keeps core's Image lightbox in step with the translated page.
 *
 * With "expand on click" on, core's Image block copies the <img alt> (and labels
 * composed from it) into Interactivity API data while the block renders
 * (block_core_image_render_lightbox) — before the_content or the final-HTML pass
 * translate it. The enlarged image and its labels are later built from that
 * data, so a translated page would still announce the source-language alt once
 * the lightbox opens.
 *
 * Runs in the final-HTML pass, after every translation of the body, and gives
 * each lightbox the image's FINAL alt — whatever pass translated it (post
 * content, template HTML), untouched where it was opted out. No queries, no
 * extra translation step. Where core keeps that copy changed across versions
 * (LingoWP supports 6.2+, the lightbox exists since 6.4):
 *
 *   6.9+     shared state, entry keyed by the figure's data-wp-key — alt, customAriaLabel
 *   6.7–6.8  shared state, entry keyed by the figure context's imageId — alt, ariaLabel
 *   6.5–6.6  the figure's own data-wp-context — alt, ariaLabel
 *   6.4–6.7  the trigger button's aria-label ("Enlarge image: %s")
 *
 * A label is rebuilt from core's own (current-locale) template, never by
 * searching for the old alt inside it — a locale may put %s anywhere. A label
 * that does not match a known template exactly is left alone. JSON is decoded
 * as objects so the rest of the shared payload round-trips unchanged ({} stays
 * {}), and re-encoded with core's own flags.
 */
final class ImageLightboxState
{
    private const SCRIPT_IDS = ['wp-script-module-data-@wordpress/interactivity', 'wp-interactivity-data'];

    /** Core's label templates around the alt (wp-includes/blocks/image.php, 6.4+). */
    private const LABEL_TEMPLATES = ['Enlarged image: %s', 'Enlarge image: %s'];

    /** Label fields core has stored next to the alt. */
    private const LABEL_FIELDS = ['customAriaLabel', 'ariaLabel'];

    public function sync(\DOMDocument $dom): void
    {
        $script    = null;
        $data      = null;
        $dataDirty = false;

        foreach (iterator_to_array($dom->getElementsByTagName('figure')) as $figure) {
            if (strpos(' ' . $figure->getAttribute('class') . ' ', ' wp-lightbox-container ') === false) {
                continue;
            }
            $img = $figure->getElementsByTagName('img')->item(0);
            if (!$img instanceof \DOMElement || !$img->hasAttribute('alt')) {
                continue;
            }
            $alt     = $img->getAttribute('alt');
            $context = json_decode($figure->getAttribute('data-wp-context'));
            $oldAlt  = null;

            // 6.5–6.6: the alt lives in the figure's own context.
            if (is_object($context) && isset($context->alt) && is_string($context->alt)) {
                $oldAlt = $context->alt;
                if ($this->retarget($context, $alt)) {
                    $figure->setAttribute('data-wp-context', $this->encode($context, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP));
                }
            }

            // 6.7+: the alt lives in shared state, keyed per image.
            $key = $figure->getAttribute('data-wp-key');
            if ($key === '' && is_object($context) && isset($context->imageId) && is_scalar($context->imageId)) {
                $key = (string) $context->imageId;
            }
            if ($key !== '') {
                if ($data === null) {
                    [$script, $data] = $this->loadData($dom);
                }
                $entry = $data->state->{'core/image'}->metadata->{$key} ?? null;
                if (is_object($entry) && isset($entry->alt) && is_string($entry->alt)) {
                    $oldAlt    = $oldAlt ?? $entry->alt;
                    $dataDirty = $this->retarget($entry, $alt) || $dataDirty;
                }
            }

            // 6.4–6.7: the trigger button's own label carries the alt.
            if ($oldAlt !== null && $oldAlt !== $alt) {
                foreach ($figure->getElementsByTagName('button') as $button) {
                    $label = $button->getAttribute('aria-label');
                    $new   = $label !== '' ? $this->relabel($label, $oldAlt, $alt) : null;
                    if ($new !== null) {
                        $button->setAttribute('aria-label', $new);
                    }
                }
            }
        }

        if ($dataDirty && $script instanceof \DOMElement) {
            $flags = JSON_HEX_TAG | JSON_UNESCAPED_SLASHES;
            if (!function_exists('is_utf8_charset') || is_utf8_charset()) {
                $flags |= JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;
            }
            $json = $this->encode($data, $flags);
            if ($json !== '') {
                while ($script->firstChild) {
                    $script->removeChild($script->firstChild);
                }
                $script->appendChild($dom->createTextNode($json));
            }
        }
    }

    /**
     * Point one alt-carrying object (state entry or figure context) at $alt,
     * rebuilding the label fields composed from the old alt.
     */
    private function retarget(object $holder, string $alt): bool
    {
        $old = (string) $holder->alt;
        if ($old === $alt) {
            return false;
        }

        foreach (self::LABEL_FIELDS as $field) {
            if (isset($holder->{$field}) && is_string($holder->{$field})) {
                $new = $this->relabel($holder->{$field}, $old, $alt);
                if ($new !== null) {
                    $holder->{$field} = $new;
                }
            }
        }
        $holder->alt = $alt;

        return true;
    }

    /** $label rebuilt around $alt, or null when it is not a known template around $old. */
    private function relabel(string $label, string $old, string $alt): ?string
    {
        foreach (self::LABEL_TEMPLATES as $template) {
            // Core's own string, in the locale core composed it in (still the
            // visitor's: GettextResolver restores it after the output buffers close).
            // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.TextDomainMismatch -- core's string from core's domain, matched against what core printed.
            $translated = translate($template, 'default');
            if (sprintf($translated, $old) === $label) {
                return sprintf($translated, $alt);
            }
        }

        return null;
    }

    /** @return array{0: ?\DOMElement, 1: object} the data script and its decoded payload (empty object if none) */
    private function loadData(\DOMDocument $dom): array
    {
        foreach ($dom->getElementsByTagName('script') as $script) {
            if (in_array($script->getAttribute('id'), self::SCRIPT_IDS, true)) {
                $data = json_decode((string) $script->textContent);
                if (is_object($data)) {
                    return [$script, $data];
                }
            }
        }

        return [null, new \stdClass()];
    }

    /** @param mixed $value */
    private function encode($value, int $flags): string
    {
        $json = wp_json_encode($value, $flags);

        return is_string($json) ? $json : '';
    }
}

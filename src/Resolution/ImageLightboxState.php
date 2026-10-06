<?php

namespace LingoWP\Resolution;

/**
 * Keeps core's Image lightbox in step with the translated page.
 *
 * With "expand on click" on, core's Image block copies the <img alt> into
 * Interactivity API state while the block renders (block_core_image_render_lightbox)
 * — before the_content or the final-HTML pass translate it. The enlarged image
 * and its dialog label are later built from that state, so a translated page
 * would still announce the source-language alt once the lightbox opens.
 *
 * Runs in the final-HTML pass, after every translation of the body: each
 * lightbox <figure> carries its state key (data-wp-key), so the state entry is
 * given the image's FINAL alt — whatever pass translated it (post content,
 * template HTML), and untouched where it was opted out. customAriaLabel is
 * core's "Enlarged image: %s" composed around the alt, so the old alt inside it
 * is swapped for the new one rather than re-composed. No queries, no extra
 * translation step.
 */
final class ImageLightboxState
{
    private const SCRIPT_ID = 'wp-script-module-data-@wordpress/interactivity';

    public function sync(\DOMDocument $dom): void
    {
        $finalAlts = [];
        foreach ($dom->getElementsByTagName('figure') as $figure) {
            $key = $figure->getAttribute('data-wp-key');
            if ($key === '' || strpos(' ' . $figure->getAttribute('class') . ' ', ' wp-lightbox-container ') === false) {
                continue;
            }
            $img = $figure->getElementsByTagName('img')->item(0);
            if ($img instanceof \DOMElement && $img->hasAttribute('alt')) {
                $finalAlts[$key] = $img->getAttribute('alt');
            }
        }
        if ($finalAlts === []) {
            return;
        }

        $script = $dom->getElementById(self::SCRIPT_ID);
        if (!$script instanceof \DOMElement) {
            foreach ($dom->getElementsByTagName('script') as $candidate) {
                if ($candidate->getAttribute('id') === self::SCRIPT_ID) {
                    $script = $candidate;
                    break;
                }
            }
        }
        if (!$script instanceof \DOMElement) {
            return;
        }

        $data = json_decode((string) $script->textContent, true);
        if (!is_array($data) || !is_array($data['state']['core/image']['metadata'] ?? null)) {
            return;
        }

        $changed = false;
        foreach ($finalAlts as $key => $alt) {
            $entry = $data['state']['core/image']['metadata'][$key] ?? null;
            if (!is_array($entry) || !isset($entry['alt']) || $entry['alt'] === $alt) {
                continue;
            }

            if (is_string($entry['customAriaLabel'] ?? null) && $entry['alt'] !== '') {
                $entry['customAriaLabel'] = str_replace((string) $entry['alt'], $alt, $entry['customAriaLabel']);
            }
            $entry['alt'] = $alt;
            $data['state']['core/image']['metadata'][$key] = $entry;
            $changed = true;
        }
        if (!$changed) {
            return;
        }

        // Same flags as core's own print (JSON_HEX_TAG keeps "</script>" out).
        $json = wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }
        while ($script->firstChild) {
            $script->removeChild($script->firstChild);
        }
        $script->appendChild($dom->createTextNode($json));
    }
}

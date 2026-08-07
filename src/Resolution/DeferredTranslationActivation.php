<?php

namespace TransLangs\Resolution;

/**
 * Deferred, memoized "should this resolver act on this request?" verdict,
 * shared by every output filter that fires early in the request lifecycle.
 *
 * shouldTranslate()/isTranslatedRequest() read conditional query tags
 * (is_feed(), is_singular(), …) that don't exist before the `wp` action
 * fires, so a verdict computed any earlier would wrongly memoize
 * "untranslated" for the whole request. $activating guards a filter that
 * fires again while this is still deciding (shouldTranslate() can itself
 * trigger output that re-enters the same filter).
 *
 * Requires the composing class to provide `$context` (RequestContext) and
 * `$language` (ResolveRequestLanguage) properties.
 */
trait DeferredTranslationActivation
{
    private ?bool $active = null;
    private bool $activating = false;
    private string $lang = '';

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

<?php

namespace LingoWP\Resolution;

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

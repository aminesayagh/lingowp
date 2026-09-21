<?php

namespace LingoWP\Resolution;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Resolution\RequestContext;

final class GettextResolver
{
    private bool $didSwitch = false;

    private ResolveRequestLanguage $language;
    private RequestContext $context;

    public function __construct(
        ResolveRequestLanguage $language,
        RequestContext $context
    ) {
        $this->language = $language;
        $this->context  = $context;
    }

    public function register(): void
    {
        add_action('wp', [$this, 'maybeSwitch']);
        add_action('shutdown', [$this, 'restore'], PHP_INT_MAX);
    }

    public function maybeSwitch(): void
    {
        if ($this->context->shouldTranslate() && $this->language->isTranslatedRequest()) {
            $this->didSwitch = switch_to_locale($this->language->resolve());
        }
    }

    public function restore(): void
    {
        if ($this->didSwitch) {
            restore_previous_locale();
        }
    }
}

<?php

namespace LingoWP\Resolution;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Resolution\RequestContext;

/**
 * Gettext resolution: WordPress-native, file-based.
 *
 * A target-language request switches the whole request to that locale via
 * WordPress's own `switch_to_locale()`, which reloads every already-loaded
 * plugin/theme textdomain from its installed `.mo`/`.l10n.php` (confirmed by
 * reading `WP_Locale_Switcher::load_translations()` on this install) — so
 * `__()`, `_e()`, `_x()`, `_n()`, etc. resolve themselves. No `gettext`
 * filters, no DB lookups, no runtime string capture.
 *
 * `switch_to_locale()` silently no-ops if the target locale has no installed
 * core language file yet (see `get_available_languages()`); in that case the
 * request simply renders in the source language, same as before this class
 * existed at all.
 */
final class GettextResolver
{
    private bool $didSwitch = false;

    public function __construct(
        private ResolveRequestLanguage $language,
        private RequestContext $context
    ) {}

    public function register(): void
    {
        add_action('wp', [$this, 'maybeSwitch']);
        // Late priority: let every other shutdown hook still see the
        // switched locale for as long as possible.
        add_action('shutdown', [$this, 'restore'], PHP_INT_MAX);
    }

    public function maybeSwitch(): void
    {
        if ($this->context->shouldTranslate() && $this->language->isTranslatedRequest()) {
            $this->didSwitch = switch_to_locale($this->language->resolve());
        }
    }

    /**
     * Pops exactly the switch made in maybeSwitch() — never
     * restore_current_locale(), which resets WordPress's entire locale-switch
     * stack and would clobber a switch made by other code in the same
     * request. Guarded by $didSwitch so a no-op switch_to_locale() (target
     * locale not installed) never pops a stack entry we didn't push.
     */
    public function restore(): void
    {
        if ($this->didSwitch) {
            restore_previous_locale();
        }
    }
}

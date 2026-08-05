<?php

namespace TransLangs\Localization;

use TransLangs\Rendering\RequestContext;
use TransLangs\Language\Application\ResolveRequestLanguage;

/**
 * Keeps a visitor's language prefix intact through WordPress's own redirects.
 *
 * wp_redirect() (and wp_safe_redirect(), which calls it) runs every redirect
 * location through the 'wp_redirect' filter before sending it — the one
 * interception point shared by wp_old_slug_redirect(), every branch of
 * redirect_canonical() (www enforcement, trailing-slash fixes, ?p=123 →
 * pretty permalink, and more), and any other same-site redirect a plugin
 * might issue. None of them know about the language prefix: this plugin has
 * no post_link/page_link filter, so every core function that builds a
 * redirect target from a permalink produces the bare, unprefixed URL. A
 * French visitor bouncing off a renamed slug's old URL, for example, would
 * otherwise land back in the default language mid-visit.
 */
class MultilingualRedirects
{
    public function __construct(
        private ResolveRequestLanguage $resolver,
        private LocalizedUrlBuilder $prefixer,
        private RequestContext $context
    ) {
    }

    public function register(): void
    {
        add_filter('wp_redirect', [$this, 'preserveLanguagePrefix'], 10, 2);
        add_action('template_redirect', [$this, 'redirectMiscasedPrefix'], -10);
    }

    /**
     * @param mixed $location
     * @param mixed $status
     * @return mixed
     */
    public function preserveLanguagePrefix($location, $status)
    {
        unset($status);

        if (!is_string($location) || $location === '') {
            return $location;
        }

        if (!$this->context->shouldTranslate() || !$this->resolver->isTranslatedRequest()) {
            return $location;
        }

        $parts = wp_parse_url($location);
        if ($parts === false) {
            return $location;
        }

        // Never touch an off-site redirect (OAuth callbacks, payment gateways, …).
        if (isset($parts['host']) && $parts['host'] !== wp_parse_url(home_url(), PHP_URL_HOST)) {
            return $location;
        }

        $path = $parts['path'] ?? '/';

        // Never prefix an admin/login destination.
        if ($this->isNonPublicPath($path)) {
            return $location;
        }

        $active = $this->resolver->resolve();

        // Already correctly prefixed — leave it exactly as the caller built
        // it. Don't round-trip an already-correct path through
        // addPrefix()/stripPrefix(): stripping a language-HOME path down to
        // just its prefix ('/fr-ca/' -> '') and rebuilding drops the trailing
        // slash ('/fr-ca/' -> '/fr-ca'), which then fights core's own
        // trailing-slash canonicalization into a redirect loop (core wants
        // '/fr-ca/', this filter kept reverting it to '/fr-ca').
        if ($this->prefixer->detectPrefix($path) === $active) {
            return $location;
        }

        $newPath = $this->prefixer->addPrefix($path, $active);

        if ($newPath === $path) {
            return $location; // already correct
        }

        $rebuilt = $newPath . $this->queryFragment($parts);

        return isset($parts['host']) ? home_url($rebuilt) : $rebuilt;
    }

    /**
     * A case-mismatched but otherwise valid language prefix (e.g. /FR/about/)
     * matches no rewrite rule at all — LanguageRouteRegistrar's cloned regexes
     * have no 'i' modifier — so it falls straight through to a plain,
     * un-intercepted 404. Recover it by 301-redirecting to the correctly-cased
     * prefix, same as WordPress's own wp_old_slug_redirect() recovers a
     * renamed slug.
     */
    public function redirectMiscasedPrefix(): void
    {
        if (!$this->context->shouldTranslate()) {
            return;
        }

        if (!function_exists('is_404') || !is_404()) {
            return;
        }

        $uri  = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return;
        }

        $segments      = explode('/', $trimmed);
        $requestedSlug = rawurldecode($segments[0]);

        $correctSlug = $this->correctedPrefixSlug($requestedSlug);
        if ($correctSlug === null) {
            return;
        }

        $segments[0] = $correctSlug;
        $corrected   = '/' . implode('/', $segments) . (str_ends_with($path, '/') ? '/' : '');
        $query       = (string) wp_parse_url($uri, PHP_URL_QUERY);

        wp_safe_redirect(home_url($corrected . ($query !== '' ? '?' . $query : '')), 301);
        exit;
    }

    /**
     * The correctly-cased slug for $requestedSlug, or null when it isn't a
     * case-mismatch of any enabled prefixed language at all (including the
     * genuinely-unknown-prefix case, which must keep falling through to a
     * normal 404) or is already exactly correct (a real 404, for some other
     * reason). Pure — no request/redirect side effects — so it's testable on
     * its own.
     */
    public function correctedPrefixSlug(string $requestedSlug): ?string
    {
        foreach ($this->prefixer->prefixedLanguages() as $lang) {
            $slug = $this->resolver->slugForLanguage($lang);
            if ($slug !== '' && strcasecmp($slug, $requestedSlug) === 0) {
                return $slug === $requestedSlug ? null : $slug;
            }
        }

        return null;
    }

    private function isNonPublicPath(string $path): bool
    {
        $trimmed = trim($path, '/');

        return str_starts_with($trimmed, 'wp-admin') || str_starts_with($trimmed, 'wp-login.php');
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function queryFragment(array $parts): string
    {
        $query    = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $query . $fragment;
    }
}

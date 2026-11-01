<?php

namespace LingoWP\LocalizationRouting;

use LingoWP\Resolution\RequestContext;
use LingoWP\Language\Application\ResolveRequestLanguage;

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
    private ResolveRequestLanguage $resolver;
    private LocalizedUrlBuilder $prefixer;
    private RequestContext $context;

    public function __construct(
        ResolveRequestLanguage $resolver,
        LocalizedUrlBuilder $prefixer,
        RequestContext $context
    ) {
        $this->resolver = $resolver;
        $this->prefixer = $prefixer;
        $this->context  = $context;
    }

    public function register(): void
    {
        add_filter('wp_redirect', [$this, 'preserveLanguagePrefix'], 10, 2);

        // Same transformation, one step earlier — see preserveLanguagePrefix()'s
        // doc for why redirect_canonical needs it BEFORE wp_redirect does.
        add_filter('redirect_canonical', [$this, 'preserveLanguagePrefix'], 10, 2);

        add_action('template_redirect', [$this, 'redirectMiscasedPrefix'], -10);
    }

    /**
     * Re-express a same-site redirect target in the active language.
     *
     * Hooked on BOTH 'wp_redirect' and 'redirect_canonical', because the two
     * are the same question asked at different moments and the answer — "this
     * path, with the current language prefix" — is identical. Neither hook's
     * second argument is used (a status code, and the requested URL).
     *
     * On 'redirect_canonical' this is not a cosmetic improvement, it fixes an
     * infinite redirect loop. A paginated singular under a language prefix
     * (/fr/my-post/2/, any post with a Page Break block) reaches
     * canonical.php's "Post paging" branch, where core rebuilds the target from
     * get_permalink() — which knows nothing about the prefix, this plugin
     * having no post_link filter. Core compares its unprefixed /my-post/2/
     * against the requested /fr/my-post/2/, finds them different, and redirects
     * to a URL that the 'wp_redirect' pass below then turns back into
     * /fr/my-post/2/: a 301 to the page we are already on, forever. Archive
     * paging escapes it only because that branch rebuilds from the requested
     * path, prefix included.
     *
     * Filtering the canonical URL itself ends it: core re-checks the filtered
     * value against the requested URL ("Yes, again -- in case the filter
     * aborted the request") and cancels when they match, which is exactly what
     * a correctly-prefixed rebuild produces. A genuine canonicalization — a
     * trailing slash, ?p=123, www enforcement — still proceeds, now with the
     * prefix already right instead of patched afterwards.
     *
     * Idempotent, so running on both hooks in one request costs nothing: an
     * already-correctly-prefixed path returns untouched.
     *
     * @param mixed $location
     * @param mixed $unused    Status code (wp_redirect) or requested URL (redirect_canonical).
     * @return mixed
     */
    public function preserveLanguagePrefix($location, $unused)
    {
        unset($unused);

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

        // Never prefix an admin/login destination — judged on the path after
        // any site folder (/blog/wp-login.php is still the login page).
        if ($this->isNonPublicPath($this->prefixer->splitSiteFolder($path)[1] ?? $path)) {
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

        // absoluteUrl(), not home_url(): $newPath already carries any site
        // folder, and home_url() would add it again (/blog/blog/fr/…).
        return isset($parts['host']) ? $this->prefixer->absoluteUrl($rebuilt) : $rebuilt;
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

        // esc_url_raw, NOT sanitize_text_field: that one strips every
        // percent-encoded octet in a loop (see wp-includes/formatting.php), so
        // a non-Latin slug like /ar/%D8%B9%D9%86/ collapsed to /ar/-/ and every
        // switcher href, hreflang target and redirect built from it pointed at
        // the site root. esc_url_raw keeps %XX and still satisfies the input
        // sanitization sniff.
        $uri    = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $target = $this->miscasedPrefixTarget($uri);
        if ($target === null) {
            return;
        }

        wp_safe_redirect($this->prefixer->absoluteUrl($target), 301);
        exit;
    }

    /**
     * Where a request with a wrongly-cased language prefix belongs:
     * "/ZZ/about/?x=1" => "/zz/about/?x=1", or null when the request's
     * prefix segment is not a miscased enabled language. Split out of
     * redirectMiscasedPrefix() — which redirects and exits — so the decision
     * is testable on its own.
     *
     * The prefix segment is the one after any site folder:
     * "/blog/ZZ/about/" => "/blog/zz/about/". The folder is put back as it came.
     */
    public function miscasedPrefixTarget(string $uri): ?string
    {
        $split = $this->prefixer->splitSiteFolder((string) wp_parse_url($uri, PHP_URL_PATH));
        if ($split === null) {
            return null;
        }

        [$folder, $path] = $split;
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return null;
        }

        $segments    = explode('/', $trimmed);
        $correctSlug = $this->correctedPrefixSlug(rawurldecode($segments[0]));
        if ($correctSlug === null) {
            return null;
        }

        $segments[0] = $correctSlug;
        $corrected   = $folder . '/' . implode('/', $segments) . (str_ends_with($path, '/') ? '/' : '');
        $query       = (string) wp_parse_url($uri, PHP_URL_QUERY);

        return $corrected . ($query !== '' ? '?' . $query : '');
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

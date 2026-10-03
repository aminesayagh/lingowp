<?php

namespace LingoWP\Resolution\Seo;

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Resolution\RequestContext;

/**
 * Default noindex for a page with no translated content yet in the visitor's
 * language (goals-seo-overview.md §5.3) — not an admin-configurable toggle.
 * A page with zero translated units in the active language is treated as not
 * started and gets noindex; the moment even one unit on it is translated, it
 * goes back to normal.
 *
 * This is a default, not an override: it only ever fills in a decision that
 * nothing else has made. If the active SEO plugin (or an admin, through it)
 * has already expressed an explicit index/noindex opinion for this post, that
 * opinion always wins — this plugin is not an SEO plugin and doesn't second
 * -guess one.
 *
 * Hooks WordPress core's own unified robots mechanism, `wp_robots`
 * (wp-includes/robots-template.php), the one standard way any plugin should
 * contribute a robots directive. Verified directly against the installed SEO
 * plugins: Yoast genuinely participates in it, so this composes correctly
 * with Yoast active. Rank Math, SEOPress, and AIOSEO each independently strip
 * this filter (`remove_all_filters('wp_robots')`) and print their own robots
 * tag from their own storage — on a site running one of those, this feature
 * simply doesn't reach the page, on the same basis already accepted
 * elsewhere in this plugin for AIOSEO's title/OG fields: an SEO plugin that
 * opts out of the standard mechanism knows better, and this plugin doesn't
 * chase it with plugin-specific workarounds.
 */
class LanguageAwareRobotsMeta
{
    private ResolveRequestLanguage $resolver;
    private RequestContext $context;
    private WpDbSourceRepository $repository;

    public function __construct(
        ResolveRequestLanguage $resolver,
        RequestContext $context,
        WpDbSourceRepository $repository
    ) {
        $this->resolver   = $resolver;
        $this->context    = $context;
        $this->repository = $repository;
    }

    public function register(): void
    {
        // After Yoast's own contribution (PHP_INT_MAX - 10), so this only
        // ever fills in a decision Yoast (or core) left genuinely open.
        add_filter('wp_robots', [$this, 'filterRobots'], PHP_INT_MAX);
    }

    /**
     * @param array<string, mixed> $robots
     * @return array<string, mixed>
     */
    public function filterRobots(array $robots): array
    {
        if (!$this->context->shouldTranslate() || !$this->resolver->isTranslatedRequest()) {
            return $robots;
        }

        if (!function_exists('is_singular') || !is_singular()) {
            return $robots;
        }

        $postId = (int) get_queried_object_id();
        if ($postId <= 0) {
            return $robots;
        }

        // Something upstream already made an explicit call — never override it.
        if (array_key_exists('noindex', $robots) || array_key_exists('index', $robots)) {
            return $robots;
        }

        if (!$this->repository->hasAnyTranslation($postId, $this->resolver->resolve())) {
            $robots['noindex'] = true;
        }

        return $robots;
    }
}

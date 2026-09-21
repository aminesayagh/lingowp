<?php

namespace LingoWP\Resolution\Seo;

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Resolution\RequestContext;

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
        add_filter('wp_robots', [$this, 'filterRobots'], PHP_INT_MAX);
    }

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

        if (array_key_exists('noindex', $robots) || array_key_exists('index', $robots)) {
            return $robots;
        }

        if (!$this->repository->hasAnyTranslation($postId, $this->resolver->resolve())) {
            $robots['noindex'] = true;
        }

        return $robots;
    }
}

<?php

namespace LingoWP\Admin;

use LingoWP\Backend\ConnectionRepository;
use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Onboarding\OnboardingPageController;

final class AdminBarTranslateLink
{
    private ConnectionRepository $connection;
    private ResolveRequestLanguage $languageResolver;

    public function __construct(ConnectionRepository $connection, ResolveRequestLanguage $languageResolver)
    {
        $this->connection       = $connection;
        $this->languageResolver = $languageResolver;
    }

    public function register(): void
    {
        add_action('admin_bar_menu', [$this, 'addNode'], 81);
    }

    public function addNode(\WP_Admin_Bar $bar): void
    {
        if (!$this->connection->isOnboardingComplete() || !current_user_can(OnboardingPageController::CAPABILITY)) {
            return;
        }

        $postId = $this->currentPostId();
        if ($postId === null) {
            return;
        }

        $args = [
            'page'       => OnboardingPageController::SLUG,
            'view'       => 'dashboard',
            'board'      => 'content',
            'collection' => 'content:' . $postId,
        ];

        if (!is_admin() && $this->languageResolver->isTranslatedRequest()) {
            $args['lang'] = $this->languageResolver->resolve();
        }

        $bar->add_node([
            'id'    => 'lingowp-translate',
            'title' => esc_html__('Translate with LingoWP', 'lingowp'),
            'href'  => add_query_arg(array_map('rawurlencode', $args), admin_url('admin.php')),
        ]);
    }

    private function currentPostId(): ?int
    {
        if (is_admin()) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $post   = (is_object($screen) && $screen->base === 'post') ? get_post() : null;
        } else {
            $post = is_singular() ? get_post(get_queried_object_id()) : null;
        }

        if (!$post instanceof \WP_Post || !is_post_type_viewable($post->post_type)) {
            return null;
        }

        return (int) $post->ID;
    }
}

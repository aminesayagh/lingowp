<?php

namespace LingoWP\Admin;

use LingoWP\Backend\ConnectionRepository;
use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Onboarding\OnboardingPageController;

/**
 * "Translate with LingoWP" in the admin bar: jumps from the post being viewed
 * (front end) or edited (classic post edit screen) straight to that post's
 * collection on the content board. Reuses the board's existing
 * "?collection=" deep link, the same one "Share collection" copies.
 *
 * Hidden until onboarding is complete, since the board has nothing to show
 * before that. Posts only for now: terms and media live in
 * whole-taxonomy / whole-library collections, not one per item.
 */
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
        // 81: right after WordPress's own "Edit Post" / "View Post" node (80).
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

        // Open the board on the language being viewed. Default-language
        // pages (and wp-admin) leave it out, so the board picks its usual
        // first target language.
        if (!is_admin() && $this->languageResolver->isTranslatedRequest()) {
            $args['lang'] = $this->languageResolver->resolve();
        }

        $bar->add_node([
            'id'    => 'lingowp-translate',
            'title' => esc_html__('Translate with Linvara', 'linvara'),
            'href'  => add_query_arg(array_map('rawurlencode', $args), admin_url('admin.php')),
        ]);
    }

    /** The post being viewed or edited, if it's a public post type; null otherwise. */
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

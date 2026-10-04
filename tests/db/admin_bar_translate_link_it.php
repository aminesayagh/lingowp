<?php

/**
 * "Translate with LingoWP" admin-bar node: shown only after onboarding, only
 * to managers, only on a public post; links to that post's board collection
 * and carries ?lang= only on a translated-language page.
 *
 * Run:  ./wp eval-file tests/db/admin_bar_translate_link_it.php
 * Runs inside a rolled-back transaction (see _harness.php).
 */

require __DIR__ . '/_harness.php';

use LingoWP\Admin\AdminBarTranslateLink;
use LingoWP\Backend\ConnectionRepository;
use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;

test_guard();
test_begin();

require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

$connection = new ConnectionRepository();
$registry   = new OptionLanguageRegistry();

/** Fresh resolver + bar each time: the resolver memoizes its language. */
function lingowp_test_node(ConnectionRepository $connection, OptionLanguageRegistry $registry): ?object
{
    $bar = new WP_Admin_Bar();
    (new AdminBarTranslateLink($connection, new ResolveRequestLanguage($registry)))->addNode($bar);

    return $bar->get_node('lingowp-translate');
}

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admins[0] ?? 0));

// An existing post, not wp_insert_post() — that would fire discovery hooks (see _harness.php).
$postId = (int) (get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids'])[0] ?? 0);
if ($postId === 0) {
    test_ok('dev site has a published post to test with', false);
    test_done();
}
query_posts(['p' => $postId]);

test_section('onboarding gate');
delete_option(ConnectionRepository::OPTION_COMPLETED);
test_ok('no node before onboarding', lingowp_test_node($connection, $registry) === null);

$connection->markOnboardingComplete();
$node = lingowp_test_node($connection, $registry);
test_ok('node after onboarding', $node !== null);
test_ok(
    'links to the post collection',
    $node !== null && strpos(rawurldecode($node->href), 'collection=content:' . $postId) !== false,
    $node->href ?? ''
);
test_ok('default-language page carries no lang', $node !== null && strpos($node->href, 'lang=') === false, $node->href ?? '');

test_section('translated-language page');
$target = $registry->getTargetLanguages()[0] ?? '';
if ($target === '') {
    test_ok('dev site has a target language to test with', false);
} else {
    set_query_var('lingowp_lang', $target);
    $node = lingowp_test_node($connection, $registry);
    test_ok('carries the viewed language', $node !== null && strpos($node->href, 'lang=' . rawurlencode($target)) !== false, $node->href ?? '');
    set_query_var('lingowp_lang', '');
}

test_section('capability and context gates');
wp_set_current_user(0);
test_ok('no node for a non-manager', lingowp_test_node($connection, $registry) === null);
wp_set_current_user((int) ($admins[0] ?? 0));

query_posts(['post_type' => 'post']);
test_ok('no node on an archive', lingowp_test_node($connection, $registry) === null);

wp_reset_query();
test_done();

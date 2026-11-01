<?php

/**
 * "First language published": a language write that takes the site from no
 * published language to one answers first_published: true, so the admin UI
 * can point the user at the language switcher settings (SwitcherSetupToast).
 * The rule is every 0 → 1 transition, not once per site.
 *
 * Covers:
 *   1  PATCH status=active with nothing published → first_published true
 *   2  publishing a second language → false
 *   3  unpublishing (status=draft) → false
 *   4  unpublish everything, publish again → true again
 *   5  PATCH without a status change → false
 *
 * Run: ./wp eval-file tests/db/language_first_published_it.php
 */

require __DIR__ . '/_harness.php';

use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;

test_guard();
test_begin();

/** PATCH /languages/{code} as the current user; returns [status, data]. */
function first_published_patch(string $code, array $body): array
{
    $request = new WP_REST_Request('PATCH', '/lingowp/v1/languages/' . $code);
    $request->set_header('content-type', 'application/json');
    $request->set_body((string) wp_json_encode($body));
    $response = rest_do_request($request);

    return [$response->get_status(), $response->get_data()];
}

$previousUser = get_current_user_id();
$admins       = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$adminId      = (int) ($admins[0] ?? 0);
test_ok('an administrator exists (setup)', $adminId > 0);
wp_set_current_user($adminId);

// Two configured (non-default) languages to publish.
$default = (new OptionLanguageRegistry())->getDefaultLanguage();
$codes   = array_values(array_diff(array_keys((new LanguageMetadataStore())->all()), [$default]));
test_ok('at least two configured languages (setup)', count($codes) >= 2, implode(',', $codes));
[$first, $second] = $codes + ['', ''];

// Nothing published (rolled back).
update_option(OptionLanguageRegistry::OPTION_ACTIVE, '');

test_section('1. First publish');
[$status, $data] = first_published_patch($first, ['status' => 'active']);
test_ok("publishing $first with nothing published → first_published true", $status === 200 && ($data['first_published'] ?? null) === true, "status $status " . wp_json_encode($data['first_published'] ?? null));

test_section('2. Second language');
[$status, $data] = first_published_patch($second, ['status' => 'active']);
test_ok("publishing $second next → first_published false", $status === 200 && ($data['first_published'] ?? null) === false, "status $status");

test_section('3. Unpublishing');
[$status, $data] = first_published_patch($second, ['status' => 'draft']);
test_ok('status=draft → first_published false', $status === 200 && ($data['first_published'] ?? null) === false, "status $status");

test_section('4. Again after unpublishing everything');
first_published_patch($first, ['status' => 'draft']);
test_ok('nothing published again (setup)', (new OptionLanguageRegistry())->getTargetLanguages() === []);
[$status, $data] = first_published_patch($second, ['status' => 'active']);
test_ok('publishing again from zero → first_published true', $status === 200 && ($data['first_published'] ?? null) === true, "status $status");

test_section('5. No status change');
update_option(OptionLanguageRegistry::OPTION_ACTIVE, '');
[$status, $data] = first_published_patch($first, ['ai_model' => 'auto']);
test_ok('PATCH without status, nothing published → first_published false', $status === 200 && ($data['first_published'] ?? null) === false, "status $status");

wp_set_current_user($previousUser);

test_done();

<?php

/**
 * Shared harness for the wp eval-file DB integration checks.
 * Not runnable on its own — required by tests/db/*_it.php.
 * Mirrors §"Phase 2 — isolation" of plan-database-pipeline-verification.md.
 *
 * Isolation contract:
 *   1. Refuse to run anywhere but the local dev database, at the expected schema
 *      version.
 *   2. Wrap everything in a transaction that is ALWAYS rolled back — on success,
 *      on failure, and on a fatal (which closes the connection, and InnoDB rolls
 *      back for us).
 *   3. Flush the object cache AFTER the rollback. This is not hygiene: tests that
 *      call update_option() write the object cache as well as the row, and a
 *      MySQL rollback does not touch it. Under a persistent backend (Redis /
 *      Memcached, which Local can enable) the stale value would outlive the
 *      process and leave the real site title corrupted. update_option also
 *      invalidates `alloptions` as a unit, so a targeted delete is not enough.
 *
 * No DDL may be run inside a test — DDL implicit-commits and would defeat (2).
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This file must be run through: wp eval-file tests/db/<name>_it.php\n");
    exit(1);
}

const TL_EXPECTED_DB_VERSION = '20';

$GLOBALS['tl_failed']    = 0;
$GLOBALS['tl_total']     = 0;
$GLOBALS['tl_finished']  = false;

/** Abort unless this is unmistakably a local dev database at the expected schema. */
function tl_guard(): void
{
    $problems = [];

    if (!defined('DB_NAME') || DB_NAME !== 'local') {
        $problems[] = 'DB_NAME is ' . (defined('DB_NAME') ? DB_NAME : '(undefined)') . ', expected "local"';
    }
    if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production') {
        $problems[] = 'environment type is production';
    }

    // maybeUpgrade() runs DDL when the stored version is lower than DB_VERSION,
    // and DDL implicit-commits. Running mid-upgrade produces uninterpretable
    // results, so refuse rather than guess.
    $version = (string) get_option('translangs_db_version', '');
    if ($version !== TL_EXPECTED_DB_VERSION) {
        $problems[] = "translangs_db_version is '{$version}', expected '" . TL_EXPECTED_DB_VERSION . "'";
    }

    if ($problems !== []) {
        fwrite(STDERR, "REFUSING TO RUN:\n  - " . implode("\n  - ", $problems) . "\n");
        exit(1);
    }
}

/** Open the transaction and arm the rollback. */
function tl_begin(): void
{
    global $wpdb;

    $wpdb->query('SET autocommit = 0');
    $wpdb->query('START TRANSACTION');

    register_shutdown_function('tl_teardown');
}

/** Roll back, then flush the object cache. Idempotent. */
function tl_teardown(): void
{
    global $wpdb;

    if (!empty($GLOBALS['tl_torn_down'])) {
        return;
    }
    $GLOBALS['tl_torn_down'] = true;

    $wpdb->query('ROLLBACK');
    $wpdb->query('SET autocommit = 1');

    // MUST come after the rollback — see the isolation contract above.
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }

    if (empty($GLOBALS['tl_finished'])) {
        fwrite(STDERR, "\n!! Script ended early (fatal or exit). Transaction rolled back.\n");
    }
}

function tl_ok(string $label, bool $cond, string $detail = ''): void
{
    $GLOBALS['tl_total']++;
    if ($cond) {
        echo "PASS  $label\n";
        return;
    }
    $GLOBALS['tl_failed']++;
    echo "FAIL  $label\n";
    if ($detail !== '') {
        echo "        $detail\n";
    }
}

function tl_section(string $name): void
{
    echo "\n$name\n";
}

/** Print the summary, tear down, and exit with the right code. */
function tl_done(): void
{
    $failed = (int) $GLOBALS['tl_failed'];
    $total  = (int) $GLOBALS['tl_total'];

    $GLOBALS['tl_finished'] = true;

    echo $failed === 0
        ? "\nAll $total assertions passed.\n"
        : "\n$failed of $total assertion(s) failed.\n";

    tl_teardown();

    if (class_exists('WP_CLI')) {
        WP_CLI::halt($failed === 0 ? 0 : 1);
    }
    exit($failed === 0 ? 0 : 1);
}

/** Raw insert helper — never wp_insert_post(), which would fire discovery hooks. */
function tl_insert(string $table, array $data): int
{
    global $wpdb;
    $wpdb->insert($table, $data);
    return (int) $wpdb->insert_id;
}

/** One scalar from a prepared query. */
function tl_var(string $sql, ...$args)
{
    global $wpdb;
    return $args === []
        ? $wpdb->get_var($sql) // nosemgrep: wpdb-query-without-prepare -- test harness; callers pass literal SQL, values go through the prepare() branch below
        : $wpdb->get_var($wpdb->prepare($sql, ...$args));
}

/** Row count matching a prepared query. */
function tl_count(string $sql, ...$args): int
{
    return (int) tl_var($sql, ...$args);
}

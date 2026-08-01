<?php
/**
 * Fixture for the `wpdb-query-without-prepare` Semgrep rule (.semgrep.yml).
 * Never loaded at runtime. Run: composer security:semgrep-selftest
 *
 * Expected: exactly 2 findings, on the two methods named bad*.
 *
 * Both receiver styles must be covered. The plugin injects $this->wpdb; the
 * tests/db/ integration scripts use the global $wpdb. An earlier version of the
 * rule passed this fixture while flagging all 73 correct global-$wpdb queries.
 */

// phpcs:ignoreFile

class TlSemgrepSqlFixture
{
    /** @var \wpdb */
    private $wpdb;

    // BAD: interpolated value straight into the query.
    public function badInterpolated(int $id): mixed
    {
        return $this->wpdb->get_var("SELECT x FROM t WHERE id = $id");
    }

    // BAD: pre-built SQL string; prepare() is not visible at the call site.
    public function badPrebuilt(string $sql): mixed
    {
        return $this->wpdb->query($sql);
    }

    // GOOD: injected wpdb, placeholders, prepare() at the call site.
    public function goodPreparedInjected(int $id): mixed
    {
        return $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT x FROM t WHERE id = %d", $id)
        );
    }

    // GOOD: same, but table name interpolated from a whitelist map.
    public function goodPreparedInterpolatedTable(string $table, int $id): mixed
    {
        return $this->wpdb->get_results(
            $this->wpdb->prepare("SELECT x FROM {$table} WHERE id = %d", $id)
        );
    }

    // GOOD: constant SQL, nothing interpolated.
    public function goodConstant(): mixed
    {
        return $this->wpdb->get_results("SELECT * FROM wp_options");
    }
}

// GOOD: global $wpdb, the form used by tests/db/*.php.
function tl_fixture_global_prepared(string $schema): mixed
{
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare("SELECT x FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s", $schema),
        ARRAY_A
    );
}

// GOOD: global $wpdb, multi-arg prepare with a concatenated placeholder value.
function tl_fixture_global_prepared_concat(string $schema): mixed
{
    global $wpdb;

    return $wpdb->get_col(
        $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s",
            $schema,
            $wpdb->prefix . 'tl\_%'
        )
    );
}

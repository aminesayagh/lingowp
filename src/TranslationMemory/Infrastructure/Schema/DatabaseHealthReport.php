<?php

namespace TransLangs\TranslationMemory\Infrastructure\Schema;

use TransLangs\TranslationMemory\Infrastructure\TranslationMemorySchema;

/**
 * Site Health diagnostic panel for the v4 schema (architecture §10, D23).
 *
 * FK/engine breakage by hosts and migration plugins is a "when", not an "if";
 * self-service diagnosis saves support hours. This reports, in Tools → Site
 * Health:
 *   - wp_posts / wp_term_taxonomy storage engines
 *   - the three v4 foreign keys (fk_src_post, fk_src_tt, fk_tr_source)
 *   - deferred-index suggestion with the current translation row count (§7)
 *   - last type 4-5 orphan sweep run (§8.2)
 *
 * Surfaces both as an Info section (debug_information) and a pass/fail status
 * test (site_status_tests). Read-only — it never alters the schema.
 */
final class DatabaseHealthReport
{
    /** Translation row count past which the deferred large-site indexes are suggested (§7, D21). */
    private const DEFERRED_INDEX_ROW_THRESHOLD = 500000;

    public function __construct(private \wpdb $wpdb) {}

    public function register(): void
    {
        add_filter('debug_information', [$this, 'addDebugSection']);
        add_filter('site_status_tests', [$this, 'addStatusTest']);
    }

    /**
     * Compute the current integrity state in one pass.
     *
     * @return array{
     *     posts_engine: ?string,
     *     tt_engine: ?string,
     *     missing_fks: string[],
     *     translation_rows: int,
     *     deferred_indexes_suggested: bool,
     *     last_sweep: string
     * }
     */
    public function gather(): array
    {
        $missing = (new ConstraintVerifier($this->wpdb))->findMissing([
            'fk_src_post'  => TranslationMemorySchema::tlSourceTable(),
            'fk_src_tt'    => TranslationMemorySchema::tlSourceTable(),
            'fk_tr_source' => TranslationMemorySchema::tlTranslationTable(),
        ]);

        $rows = (int) $this->wpdb->get_var( // phpcs:ignore WordPress.DB
            'SELECT COUNT(*) FROM ' . TranslationMemorySchema::tlTranslationTable()
        );

        return [
            'posts_engine'               => $this->engineOf($this->wpdb->posts),
            'tt_engine'                  => $this->engineOf($this->wpdb->term_taxonomy),
            'missing_fks'                => $missing,
            'translation_rows'           => $rows,
            'deferred_indexes_suggested' => $rows > self::DEFERRED_INDEX_ROW_THRESHOLD,
            'last_sweep'                 => (string) get_option('translangs_age_out_last_run', ''),
        ];
    }

    /**
     * Add the "TransLangs DB integrity" section to Tools → Site Health → Info.
     *
     * @param array<string, mixed> $info
     * @return array<string, mixed>
     */
    public function addDebugSection(array $info): array
    {
        $state = $this->gather();

        $info['translangs_db'] = [
            'label'       => __('TransLangs DB integrity', 'translangs'),
            'description' => __('Storage engines, foreign keys, and maintenance state for the TransLangs translation tables.', 'translangs'),
            'fields'      => [
                'posts_engine' => [
                    'label' => 'wp_posts engine',
                    'value' => $this->engineLabel($state['posts_engine']),
                ],
                'tt_engine' => [
                    'label' => 'wp_term_taxonomy engine',
                    'value' => $this->engineLabel($state['tt_engine']),
                ],
                'fk_src_post' => [
                    'label' => 'fk_src_post',
                    'value' => $this->fkLabel('fk_src_post', $state['missing_fks']),
                ],
                'fk_src_tt' => [
                    'label' => 'fk_src_tt',
                    'value' => $this->fkLabel('fk_src_tt', $state['missing_fks']),
                ],
                'fk_tr_source' => [
                    'label' => 'fk_tr_source',
                    'value' => $this->fkLabel('fk_tr_source', $state['missing_fks']),
                ],
                'deferred_indexes' => [
                    'label' => __('Deferred indexes', 'translangs'),
                    'value' => $state['deferred_indexes_suggested']
                        /* translators: %s: translation row count. */
                        ? sprintf(__('suggested (row count: %s)', 'translangs'), number_format_i18n($state['translation_rows']))
                        : __('not needed', 'translangs'),
                ],
                'orphan_sweep' => [
                    'label' => __('Orphan sweep (types 4-5)', 'translangs'),
                    'value' => $state['last_sweep'] !== ''
                        ? $state['last_sweep']
                        : __('never run', 'translangs'),
                ],
            ],
        ];

        return $info;
    }

    /**
     * Register the direct status test shown under Site Health → Status.
     *
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public function addStatusTest(array $tests): array
    {
        $tests['direct']['translangs_db_integrity'] = [
            'label' => __('TransLangs database integrity', 'translangs'),
            'test'  => [$this, 'runIntegrityTest'],
        ];

        return $tests;
    }

    /**
     * @return array<string, mixed> Site Health test result.
     */
    public function runIntegrityTest(): array
    {
        $state    = $this->gather();
        $myisam   = array_filter(
            ['wp_posts' => $state['posts_engine'], 'wp_term_taxonomy' => $state['tt_engine']],
            fn(?string $engine): bool => $engine !== null && strtoupper($engine) !== 'INNODB'
        );
        $critical = $myisam !== [] || $state['missing_fks'] !== [];

        $result = [
            'label'       => __('TransLangs translation tables are healthy', 'translangs'),
            'status'      => 'good',
            'badge'       => [
                'label' => __('TransLangs', 'translangs'),
                'color' => 'blue',
            ],
            'description' => '<p>' . esc_html__('Parent tables are InnoDB and all foreign keys are present.', 'translangs') . '</p>',
            'actions'     => '',
            'test'        => 'translangs_db_integrity',
        ];

        if ($critical) {
            $problems = [];
            foreach ($myisam as $table => $engine) {
                /* translators: 1: table name, 2: storage engine. */
                $problems[] = sprintf(esc_html__('%1$s uses %2$s instead of InnoDB.', 'translangs'), esc_html($table), esc_html((string) $engine));
            }
            if ($state['missing_fks'] !== []) {
                /* translators: %s: comma-separated list of foreign key names. */
                $problems[] = sprintf(esc_html__('Missing foreign keys: %s.', 'translangs'), esc_html(implode(', ', $state['missing_fks'])));
            }

            $result['status']        = 'critical';
            $result['label']         = __('TransLangs translation tables have integrity problems', 'translangs');
            $result['description']   = '<p>' . implode('<br>', $problems) . '</p>';
            $result['badge']['color'] = 'red';
        } elseif ($state['deferred_indexes_suggested']) {
            $result['status']      = 'recommended';
            $result['label']       = __('TransLangs large-site indexes are recommended', 'translangs');
            $result['description'] = '<p>' . sprintf(
                /* translators: %s: translation row count. */
                esc_html__('Your translation table has %s rows; enabling the deferred large-site indexes will keep admin screens fast.', 'translangs'),
                esc_html(number_format_i18n($state['translation_rows']))
            ) . '</p>';
        }

        return $result;
    }

    private function engineLabel(?string $engine): string
    {
        if ($engine === null) {
            return __('unknown', 'translangs');
        }

        return strtoupper($engine) === 'INNODB' ? 'InnoDB ✔' : $engine . ' ✘';
    }

    /** @param string[] $missing */
    private function fkLabel(string $fk, array $missing): string
    {
        return in_array($fk, $missing, true) ? __('missing ✘', 'translangs') : __('exists ✔', 'translangs');
    }

    private function engineOf(string $table): ?string
    {
        $engine = $this->wpdb->get_var( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "SELECT ENGINE FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table
            )
        );

        return is_string($engine) ? $engine : null;
    }
}

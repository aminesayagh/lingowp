<?php

namespace LingoWP\Admin;

use LingoWP\Database\DatabaseHealthCheck;
use LingoWP\Database\TranslationMemorySchema;

/**
 * Site Health diagnostic panel for the schema.
 *
 * FK/engine breakage by hosts and migration plugins is a "when", not an "if";
 * self-service diagnosis saves support hours. This reports, in Tools → Site
 * Health:
 *   - wp_posts / wp_term_taxonomy storage engines
 *   - the five foreign keys (fk_post_owner, fk_meta_owner, fk_term_owner, fk_option_owner, fk_meta_tr_parent)
 *   - deferred-index suggestion with the current translation row count
 *   - last orphan sweep run
 *
 * Surfaces both as an Info section (debug_information) and a pass/fail status
 * test (site_status_tests). Read-only — it never alters the schema. The
 * integrity state itself is computed by Database\DatabaseHealthCheck; this
 * class only knows how to label and present it.
 */
final class DatabaseHealthReport
{
    private DatabaseHealthCheck $check;

    public function __construct(DatabaseHealthCheck $check)
    {
        $this->check = $check;
    }

    public function register(): void
    {
        add_filter('debug_information', [$this, 'addDebugSection']);
        add_filter('site_status_tests', [$this, 'addStatusTest']);
    }

    /**
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
        return $this->check->gather();
    }

    /**
     * Add the "LingoWP DB integrity" section to Tools → Site Health → Info.
     *
     * @param array<string, mixed> $info
     * @return array<string, mixed>
     */
    public function addDebugSection(array $info): array
    {
        $state = $this->gather();

        $fields = [
            'posts_engine' => [
                'label' => 'wp_posts engine',
                'value' => $this->engineLabel($state['posts_engine']),
            ],
            'tt_engine' => [
                'label' => 'wp_term_taxonomy engine',
                'value' => $this->engineLabel($state['tt_engine']),
            ],
        ];

        foreach (array_keys(TranslationMemorySchema::expectedForeignKeys()) as $fk) {
            $fields[$fk] = [
                'label' => $fk,
                'value' => $this->fkLabel($fk, $state['missing_fks']),
            ];
        }

        $info['lingowp_db'] = [
            'label'       => __('Linvara DB integrity', 'linvara'),
            'description' => __('Storage engines, foreign keys, and maintenance state for the Linvara translation tables.', 'linvara'),
            'fields'      => $fields + [
                'deferred_indexes' => [
                    'label' => __('Deferred indexes', 'linvara'),
                    'value' => $state['deferred_indexes_suggested']
                        /* translators: %s: translation row count. */
                        ? sprintf(__('suggested (row count: %s)', 'linvara'), number_format_i18n($state['translation_rows']))
                        : __('not needed', 'linvara'),
                ],
                'orphan_sweep' => [
                    'label' => __('Orphan sweep (types 4-5)', 'linvara'),
                    'value' => $state['last_sweep'] !== ''
                        ? $state['last_sweep']
                        : __('never run', 'linvara'),
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
        $tests['direct']['lingowp_db_integrity'] = [
            'label' => __('Linvara database integrity', 'linvara'),
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
            'label'       => __('Linvara translation tables are healthy', 'linvara'),
            'status'      => 'good',
            'badge'       => [
                'label' => __('Linvara', 'linvara'),
                'color' => 'blue',
            ],
            'description' => '<p>' . esc_html__('Parent tables are InnoDB and all foreign keys are present.', 'linvara') . '</p>',
            'actions'     => '',
            'test'        => 'lingowp_db_integrity',
        ];

        if ($critical) {
            $problems = [];
            foreach ($myisam as $table => $engine) {
                /* translators: 1: table name, 2: storage engine. */
                $problems[] = sprintf(esc_html__('%1$s uses %2$s instead of InnoDB.', 'linvara'), esc_html($table), esc_html((string) $engine));
            }
            if ($state['missing_fks'] !== []) {
                /* translators: %s: comma-separated list of foreign key names. */
                $problems[] = sprintf(esc_html__('Missing foreign keys: %s.', 'linvara'), esc_html(implode(', ', $state['missing_fks'])));
            }

            $result['status']        = 'critical';
            $result['label']         = __('Linvara translation tables have integrity problems', 'linvara');
            $result['description']   = '<p>' . implode('<br>', $problems) . '</p>';
            $result['badge']['color'] = 'red';
        } elseif ($state['deferred_indexes_suggested']) {
            $result['status']      = 'recommended';
            $result['label']       = __('Linvara large-site indexes are recommended', 'linvara');
            $result['description'] = '<p>' . sprintf(
                /* translators: %s: translation row count. */
                esc_html__('Your translation table has %s rows; enabling the deferred large-site indexes will keep admin screens fast.', 'linvara'),
                esc_html(number_format_i18n($state['translation_rows']))
            ) . '</p>';
        }

        return $result;
    }

    private function engineLabel(?string $engine): string
    {
        if ($engine === null) {
            return __('unknown', 'linvara');
        }

        return strtoupper($engine) === 'INNODB' ? 'InnoDB ✔' : $engine . ' ✘';
    }

    /** @param string[] $missing */
    private function fkLabel(string $fk, array $missing): string
    {
        return in_array($fk, $missing, true) ? __('missing ✘', 'linvara') : __('exists ✔', 'linvara');
    }
}

<?php

namespace TransLangs\Api;

use TransLangs\Language\Infrastructure\OptionLanguageRegistry;

/**
 * Schedules the background site-metadata sync (P4).
 *
 * WordPress change hooks only ever *queue* one debounced Action Scheduler job —
 * they never call the backend inline, so saving General settings or toggling a
 * plugin never blocks on our network call. Rapid changes coalesce into the single
 * pending job, which sends the latest state when it runs. Categories are not
 * synced here (they only change via explicit configure/settings save, which sends
 * them directly).
 */
final class SiteMetadataSyncScheduler
{
    private const DEBOUNCE = 30; // seconds

    public function __construct(private ConnectionRepository $connection) {}

    public function register(): void
    {
        $trigger = [$this, 'schedule'];

        add_action('update_option_blogname', $trigger);
        add_action('update_option_blogdescription', $trigger);
        add_action('switch_theme', $trigger);
        add_action('activated_plugin', $trigger);
        add_action('deactivated_plugin', $trigger);
        add_action('translangs_language_roster_changed', $trigger);
        add_action('update_option_' . OptionLanguageRegistry::OPTION_SOURCE_LOCALE, $trigger);
    }

    /** Extra hook args are ignored — the job always builds the full current metadata. */
    public function schedule(): void
    {
        if (!$this->connection->isConnected()) {
            return;
        }
        if (!function_exists('as_schedule_single_action') || !function_exists('as_has_scheduled_action')) {
            return;
        }
        if (as_has_scheduled_action(SiteMetadataSyncJob::HOOK, [], SiteMetadataSyncJob::GROUP)) {
            return; // a job is already pending; it will send the latest state
        }

        as_schedule_single_action(time() + self::DEBOUNCE, SiteMetadataSyncJob::HOOK, [], SiteMetadataSyncJob::GROUP);
    }
}

<?php

namespace TransLangs\Backend;

use TransLangs\Language\Infrastructure\OptionLanguageRegistry;

/**
 * Schedules the background site-metadata sync (P4).
 *
 * PUT /auth/site only accepts {default_language} now, so the only change that
 * needs syncing is the source locale. The hook still queues one debounced
 * Action Scheduler job rather than calling the backend inline, so changing the
 * site language never blocks on our network call.
 */
final class SiteMetadataSyncScheduler
{
    private const DEBOUNCE = 30; // seconds

    public function __construct(private ConnectionRepository $connection) {}

    public function register(): void
    {
        add_action('update_option_' . OptionLanguageRegistry::OPTION_SOURCE_LOCALE, [$this, 'schedule']);
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

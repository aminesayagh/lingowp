<?php

namespace TransLangs\Setup;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use TransLangs\PublicSite\Infrastructure\LanguageRouteRegistrar;
use TransLangs\TranslationMemory\Infrastructure\InitialDiscoveryScan;
use TransLangs\TranslationMemory\Infrastructure\Schema\SchemaFailure;
use TransLangs\TranslationMemory\Infrastructure\TranslationMemorySchema;

/**
 * Runs once on plugin activation.
 *
 * Responsibilities:
 *  - create database tables
 *  - set default options
 *  - flush rewrite rules a single time (after rules are registered)
 */
class Activator
{
    public static function run(): void
    {
        // Abort activation with a specific, human-readable message rather than a
        // white screen or raw SQL error when the DB prerequisites are not met
        // (non-InnoDB parent tables, or FKs that could not be created — §10).
        try {
            TranslationMemorySchema::install();
        } catch (SchemaFailure $failure) {
            wp_die(
                esc_html($failure->getMessage()),
                esc_html__('TransLangs activation failed', 'translangs'),
                ['back_link' => true]
            );
        }

        TranslationMemorySchema::setDefaultOptions();

        // Carry any previously configured languages over to the v4 code lists.
        $registry = new OptionLanguageRegistry();
        $registry->seedFromLegacyOption();

        // Request a one-time initial discovery scan; the batched Action Scheduler
        // job is enqueued on the next init, once the AS store is ready.
        update_option(InitialDiscoveryScan::OPTION_FLAG, 1);

        // Register the rewrite compilation filter now, then flush so language
        // rule copies are included immediately. The normal plugin boot may not
        // have run during activation.
        $resolver = new ResolveRequestLanguage($registry);
        (new LanguageRouteRegistrar($resolver))->registerRewriteRulesFilter();

        // Flushing is expensive, so it happens only on activation, never per request.
        flush_rewrite_rules();
        update_option('translangs_rewrite_rules_version', LanguageRouteRegistrar::REWRITE_RULES_VERSION, false);
    }
}

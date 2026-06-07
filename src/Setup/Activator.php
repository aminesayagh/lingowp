<?php

namespace TransLangs\Setup;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Infrastructure\WpDbLanguageRegistry;
use TransLangs\PublicSite\Infrastructure\LanguageRouteRegistrar;
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
        TranslationMemorySchema::install();
        TranslationMemorySchema::setDefaultOptions();

        // Register the rewrite compilation filter now, then flush so language
        // rule copies are included immediately. The normal plugin boot may not
        // have run during activation.
        global $wpdb;
        $resolver = new ResolveRequestLanguage(
            new WpDbLanguageRegistry($wpdb)
        );
        (new LanguageRouteRegistrar($resolver))->registerRewriteRulesFilter();

        // Flushing is expensive, so it happens only on activation, never per request.
        flush_rewrite_rules();
        update_option('translangs_rewrite_rules_version', LanguageRouteRegistrar::REWRITE_RULES_VERSION, false);
    }
}

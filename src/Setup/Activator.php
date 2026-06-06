<?php

namespace TransLangs\Setup;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Domain\LocaleNormalizer;
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
        
        // Register our rewrite rules now, then flush so they take effect
        // immediately. The init hook hasn't run during activation, so we
        // register them directly here before flushing.
        global $wpdb;
        $resolver = new ResolveRequestLanguage(
            new WpDbLanguageRegistry($wpdb, new LocaleNormalizer())
        );
        (new LanguageRouteRegistrar($resolver))->addRewriteRules();
 
        // Flushing is expensive, so it happens only on activation, never per request.
        flush_rewrite_rules();
    }
}

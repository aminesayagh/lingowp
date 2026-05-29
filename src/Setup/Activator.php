<?php

namespace TransLangs\Setup;

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
        Schema::install();
        Schema::setDefaultOptions();
        
        // Register our rewrite rules now, then flush so they take effect
        // immediately. The init hook hasn't run during activation, so we
        // register them directly here before flushing.
        $resolver = new \TransLangs\Frontend\LanguageResolver();
        (new \TransLangs\Frontend\Router($resolver))->addRewriteRules();
 
        // Flushing is expensive, so it happens only on activation, never per request.
        flush_rewrite_rules();
    }
}
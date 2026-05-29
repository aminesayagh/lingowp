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

        // Rewrite rules for /fr/ etc. are registered on 'init' (Phase 4).
        // We flush once here so those rules take effect immediately.
        // Flushing is expensive, so it happens only on activation, never per request.
        flush_rewrite_rules();
    }
}
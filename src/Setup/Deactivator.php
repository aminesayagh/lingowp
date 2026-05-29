<?php

namespace TransLangs\Setup;

/**
 * Runs once on plugin deactivation.
 *
 * Deactivation is reversible, so we do NOT drop tables or delete options here.
 * Destructive cleanup belongs in uninstall.php, which runs only on delete.
 * We only flush rewrite rules so our custom routes stop being served.
 */
class Deactivator
{
    public static function run(): void
    {
        flush_rewrite_rules();
    }
}
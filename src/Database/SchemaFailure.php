<?php

namespace LingoWP\Database;

use RuntimeException;

/**
 * Thrown when FK verification fails during schema installation.
 * The installer rolls back all created tables before throwing.
 */
final class SchemaFailure extends RuntimeException
{
    /** @param string[] $missing Constraint names that were absent in information_schema. */
    public static function missingForeignKeys(array $missing): self
    {
        $list = implode(', ', $missing);
        return new self(
            "Lingostack activation failed: the following foreign key constraints could not be created: {$list}. " .
            "This usually means wp_posts or wp_term_taxonomy uses MyISAM, or the DB user lacks the REFERENCES privilege. " .
            "All Lingostack tables have been dropped. Ensure InnoDB is available and try activating again."
        );
    }

    /**
     * Thrown before any table is created when a required parent table is not
     * InnoDB. MyISAM core tables still exist in the wild; the FK chain that
     * makes translation orphans impossible cannot be built against them (§10).
     */
    public static function parentNotInnoDB(string $table, string $engine): self
    {
        return new self(
            "Your {$table} table uses {$engine}; convert it to InnoDB to use this plugin. " .
            "Lingostack relies on InnoDB foreign keys to keep translations consistent with your content."
        );
    }
}

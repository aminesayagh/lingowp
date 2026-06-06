<?php

namespace TransLangs\TranslationMemory\Infrastructure\Schema;

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
            "TransLangs activation failed: the following foreign key constraints could not be created: {$list}. " .
            "This usually means wp_posts or wp_term_taxonomy uses MyISAM, or the DB user lacks the REFERENCES privilege. " .
            "All TransLangs tables have been dropped. Ensure InnoDB is available and try activating again."
        );
    }
}

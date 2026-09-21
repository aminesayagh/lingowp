<?php

namespace LingoWP\Database;

use RuntimeException;

final class SchemaFailure extends RuntimeException
{
    public static function missingForeignKeys(array $missing): self
    {
        $list = implode(', ', $missing);
        return new self(
            "LingoWP activation failed: the following foreign key constraints could not be created: {$list}. " .
            "This usually means wp_posts or wp_term_taxonomy uses MyISAM, or the DB user lacks the REFERENCES privilege. " .
            "All LingoWP tables have been dropped. Ensure InnoDB is available and try activating again."
        );
    }

    public static function parentNotInnoDB(string $table, string $engine): self
    {
        return new self(
            "Your {$table} table uses {$engine}; convert it to InnoDB to use this plugin. " .
            "LingoWP relies on InnoDB foreign keys to keep translations consistent with your content."
        );
    }
}

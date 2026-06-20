<?php

namespace TransLangs\Core\Source\Value;

/**
 * Status vocabulary (v5). The column is a SIGNED tinyint so "done" reads as a
 * single `status > 0` instead of an `IN (1, 2)` list:
 *
 *   > 0  done            (machine / reviewed)
 *   = 0  discovered      (parent: translatable source)
 *   < 0  excluded        (child: needs_review · parent: ignored)
 *
 * A row is either a parent or a child, never both, so -1 unambiguously means
 * "ignored" on a parent and "needs_review" on a child.
 *
 * There is no stale parent status and no "original changed" derivation: a
 * source-text edit deletes the stale parent and seeds the new translation as
 * needs_review, so child status alone drives the admin queue.
 */
final class TranslationStatus
{
    public const MACHINE      = 1;
    public const REVIEWED     = 2;
    public const NEEDS_REVIEW = -1;

    public const SOURCE_DISCOVERED = 0;
    public const SOURCE_IGNORED    = -1;

    private function __construct() {}
}

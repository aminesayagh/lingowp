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
 * There is no stale parent status and no "original changed" derivation for
 * post/meta/term/option: a source-text edit deletes the stale parent and
 * seeds the new translation as needs_review, so child status alone drives the
 * admin queue. tl_html is the one deliberate exception — its identity is a
 * bare content hash with no owning entity to reconcile against, so a crawl
 * can never be sure "not found this pass" means "gone" rather than "outside
 * this crawl's URL scope" or "one fetch failed". SOURCE_STALE flags a
 * candidate for admin review instead of deleting it outright (see
 * WpDbSourceTranslationRepository::flagStaleHtmlSources()).
 */
final class TranslationStatus
{
    public const MACHINE      = 1;
    public const REVIEWED     = 2;
    public const NEEDS_REVIEW = -1;

    public const SOURCE_DISCOVERED = 0;
    public const SOURCE_IGNORED    = -1;
    public const SOURCE_STALE      = 2;

    private function __construct() {}
}

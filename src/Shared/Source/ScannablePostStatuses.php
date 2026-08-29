<?php

namespace LingoWP\Shared\Source;

/**
 * Post statuses eligible for LingoWP discovery and for the admin board's
 * "needs attention" listing (publish, plus inherit for attachments).
 *
 * StructuredSourceScanner (discovery) and WpDbBoardQueries (the board's read
 * queries) MUST agree on this list, or a post excluded from discovery could
 * still show as needing translation, or a live post could be hidden from the
 * board — the same reasoning that keeps TranslatableMetaKeys layer-neutral
 * rather than owned by either side.
 */
final class ScannablePostStatuses
{
    public const ALL = ['publish', 'inherit'];

    private function __construct() {}
}

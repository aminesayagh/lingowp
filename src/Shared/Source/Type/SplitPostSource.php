<?php

namespace LingoWP\Shared\Source\Type;

/**
 * One block-level piece of a split rich-HTML post field (post_content).
 *
 * A split piece is just a post parent row whose original_text is the
 * encoded unit string (inline tags as `{n}` placeholders) and whose
 * original_hash therefore differs per piece. Pieces of the same field share
 * (post_id, field) and disambiguate on original_hash — no separate key scheme.
 * Editing one paragraph yields a new parent (that piece falls back to original
 * until re-translated) while its siblings are untouched.
 */
final class SplitPostSource extends PostSource
{
}

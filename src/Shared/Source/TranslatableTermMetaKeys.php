<?php

namespace LingoWP\Shared\Source;

/**
 * The single source of truth for which term-meta keys are translatable
 * (the term-meta counterpart of TranslatableMetaKeys, for arbitrary per-term
 * fields such as an ACF field on a category).
 *
 * Unlike post meta, there is no known-upfront SEO-style union to default to —
 * term meta is entirely site-specific, so this starts empty and is opted into
 * per site via the `lingowp_translatable_term_meta_keys` filter.
 *
 * Discovery (StructuredSourceScanner) and render resolution
 * (StructuredRenderResolver) MUST agree on this list, or a key discovered on
 * save would never resolve on render — same reasoning as TranslatableMetaKeys.
 */
final class TranslatableTermMetaKeys
{
    private function __construct() {}

    /**
     * @return string[] Allow-listed term-meta keys.
     */
    public static function keys(): array
    {
        $keys = function_exists('apply_filters')
            ? apply_filters('lingowp_translatable_term_meta_keys', [])
            : [];

        return array_values(array_filter(
            array_map('strval', is_array($keys) ? $keys : []),
            static fn(string $key): bool => $key !== ''
        ));
    }
}

<?php

namespace LingoWP\Shared\Source;

final class TranslatableTermMetaKeys
{
    private function __construct() {}

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

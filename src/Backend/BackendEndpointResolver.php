<?php

namespace LingoWP\Backend;

final class BackendEndpointResolver
{
    public const OPTION_ORIGIN = 'lingowp_api_origin';

    private const VERSION_PATH = '/api/v1';

    private const DEFAULT_ORIGIN = 'https://cloud.lingowp.com';

    public function baseUrl(): string
    {
        return $this->origin() . self::VERSION_PATH;
    }

    public function origin(): string
    {
        $filtered = apply_filters('lingowp_api_origin', '');
        if (is_string($filtered) && $filtered !== '') {
            return $this->normalize($filtered);
        }

        $option = (string) get_option(self::OPTION_ORIGIN, '');
        if ($option !== '') {
            return $this->normalize($option);
        }

        return self::DEFAULT_ORIGIN;
    }

    public function resetOrigin(): void
    {
        delete_option(self::OPTION_ORIGIN);
    }

    private function normalize(string $origin): string
    {
        return untrailingslashit(trim($origin));
    }
}

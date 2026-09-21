<?php

namespace LingoWP\Extraction\Html;

final class CrawlSkipHooks
{
    private const REDUNDANT = ['comment_text', 'the_title', 'get_the_excerpt'];

    private const DYNAMIC = ['wp_date' => 'date', 'wc_price' => 'price'];

    private const BLOCK = ['the_content'];

    private function __construct()
    {
    }

    public static function redundantWithStructuredResolvers(): array
    {
        return self::REDUNDANT;
    }

    public static function dynamicallyComputed(): array
    {
        return array_keys(self::DYNAMIC);
    }

    public static function blockLevel(): array
    {
        return self::BLOCK;
    }
}

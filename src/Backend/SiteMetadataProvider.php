<?php

namespace LingoWP\Backend;

use LingoWP\Language\Domain\LanguageRegistry;

final class SiteMetadataProvider
{
    public const OPTION_CATEGORIES = 'lingowp_categories';

    private LanguageRegistry $languages;
    private ConnectionRepository $connection;

    public function __construct(
        LanguageRegistry $languages,
        ConnectionRepository $connection
    ) {
        $this->languages  = $languages;
        $this->connection = $connection;
    }

    public function buildForCreate(): array
    {
        return [
            'domain'      => $this->connection->domain(),
            'admin_email' => (string) get_option('admin_email', ''),
        ];
    }

    public function categories(): array
    {
        $stored = get_option(self::OPTION_CATEGORIES, []);

        return is_array($stored) ? array_values(array_map('strval', $stored)) : [];
    }

    public function resetCategories(): void
    {
        delete_option(self::OPTION_CATEGORIES);
    }
}

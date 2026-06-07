<?php

namespace TransLangs\Cli;

use TransLangs\TranslationMemory\Infrastructure\WpDbTranslationMemoryRepository;

/**
 * Clean up unwanted captured strings from the translation memory.
 */
class CleanupCommand
{
    private const ADMIN_BAR_STRINGS = [
        'Howdy',
        'Customize',
        'Edit Page',
        'Edit Post',
        'Edit',
        'New',
        'Dashboard',
        'Search',
        'Visit Site',
        'Updates',
    ];

    public function __construct(private WpDbTranslationMemoryRepository $translationMemory) {}

    /**
     * Remove WordPress admin bar strings from translation memory.
     *
     * ## EXAMPLES
     *
     *   wp translangs cleanup admin-bar
     *
     * @when after_wp_load
     */
    public function adminBar(): void
    {
        $deleted = $this->translationMemory->deleteI18nByOriginalTexts(self::ADMIN_BAR_STRINGS);

        if ($deleted === 0) {
            \WP_CLI::success('No admin bar strings found to delete.');
            return;
        }

        \WP_CLI::success("Deleted {$deleted} admin bar string(s) from translation memory.");
    }
}

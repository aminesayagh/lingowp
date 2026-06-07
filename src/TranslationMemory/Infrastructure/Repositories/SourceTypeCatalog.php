<?php

namespace TransLangs\TranslationMemory\Infrastructure\Repositories;

/**
 * Maps stored translation rows to the source-type tabs on the Translation
 * Memory screen.
 *
 * post / postmeta rows are bucketed by their parent post_type; terms rows are
 * "taxonomies"; i18n rows are theme/plugin strings. This is the single place
 * that knows the tab vocabulary, its labels, and how each tab translates into
 * SQL — both the admin query (filtering/counting) and the controller (labels)
 * read from here so they can never drift apart.
 */
final class SourceTypeCatalog
{
    public const ATTACHMENTS  = 'attachments';
    public const PAGES        = 'pages';
    public const POSTS        = 'posts';
    public const PRODUCTS     = 'products';
    public const MENUS        = 'menus';
    public const BLOCKS       = 'blocks';
    public const TEMPLATES    = 'templates';
    public const CPT          = 'cpt';
    public const TAXONOMIES   = 'taxonomies';
    public const THEME_PLUGIN = 'theme_plugin';

    /**
     * WordPress post_type => tab slug. Several types may map to one tab
     * (e.g. wp_template + wp_template_part → templates). Any post_type not
     * listed here falls through to the CPT tab.
     */
    private const POST_TYPE_MAP = [
        'attachment'       => self::ATTACHMENTS,
        'page'             => self::PAGES,
        'post'             => self::POSTS,
        'product'          => self::PRODUCTS,
        'nav_menu_item'    => self::MENUS,
        'wp_block'         => self::BLOCKS,
        'wp_template'      => self::TEMPLATES,
        'wp_template_part' => self::TEMPLATES,
    ];

    /**
     * Tab slugs in display order.
     *
     * @return string[]
     */
    public static function orderedSlugs(): array
    {
        return [
            self::ATTACHMENTS,
            self::PAGES,
            self::POSTS,
            self::PRODUCTS,
            self::MENUS,
            self::BLOCKS,
            self::TEMPLATES,
            self::CPT,
            self::TAXONOMIES,
            self::THEME_PLUGIN,
        ];
    }

    public static function label(string $slug): string
    {
        $labels = [
            self::ATTACHMENTS  => __('Attachments', 'translangs'),
            self::PAGES        => __('Pages', 'translangs'),
            self::POSTS        => __('Posts', 'translangs'),
            self::PRODUCTS     => __('Products', 'translangs'),
            self::MENUS        => __('Menus', 'translangs'),
            self::BLOCKS       => __('Blocks', 'translangs'),
            self::TEMPLATES    => __('Templates', 'translangs'),
            self::CPT          => __('Custom Post Types', 'translangs'),
            self::TAXONOMIES   => __('Taxonomies', 'translangs'),
            self::THEME_PLUGIN => __('Theme / Plugin strings', 'translangs'),
        ];

        return $labels[$slug] ?? $slug;
    }

    /**
     * Route tables that can hold rows for a tab.
     *
     * @return string[]
     */
    public static function routeTablesFor(string $slug): array
    {
        return match ($slug) {
            self::THEME_PLUGIN => ['i18n'],
            self::TAXONOMIES   => ['terms'],
            default            => ['post', 'postmeta'],
        };
    }

    /**
     * post_type WHERE fragment for a post-backed tab, scoped to $column.
     * Returns [null, []] for terms/i18n tabs, which carry no post_type.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    public static function postTypeConstraint(string $slug, string $column): array
    {
        if ($slug === self::CPT) {
            $known        = array_keys(self::POST_TYPE_MAP);
            $placeholders = implode(',', array_fill(0, count($known), '%s'));

            return ["{$column} NOT IN ({$placeholders})", array_values($known)];
        }

        $types = array_keys(self::POST_TYPE_MAP, $slug, true);
        if ($types !== []) {
            $placeholders = implode(',', array_fill(0, count($types), '%s'));

            return ["{$column} IN ({$placeholders})", array_values($types)];
        }

        return [null, []];
    }

    /**
     * SQL CASE expression mapping a post_type $column to a tab slug.
     * Used by the counts query. The post_type literals are fixed constants,
     * never user input, so they are safe to embed directly.
     */
    public static function postTypeCaseExpression(string $column): string
    {
        $bySlug = [];
        foreach (self::POST_TYPE_MAP as $type => $slug) {
            $bySlug[$slug][] = $type;
        }

        $whens = '';
        foreach ($bySlug as $slug => $types) {
            $list  = implode(',', array_map(static fn(string $t): string => "'{$t}'", $types));
            $whens .= " WHEN {$column} IN ({$list}) THEN '{$slug}'";
        }

        return "CASE{$whens} ELSE '" . self::CPT . "' END";
    }
}

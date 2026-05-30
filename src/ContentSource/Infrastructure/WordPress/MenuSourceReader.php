<?php

namespace TransLangs\ContentSource\Infrastructure\WordPress;

use TransLangs\ContentSource\Domain\SourceReader;
use TransLangs\ContentSource\Domain\SourceString;
use TransLangs\TranslationMemory\Domain\TranslationSourceType;

/**
 * Reads translatable text from navigation menu items.
 *
 * A menu item can carry a custom label (its "navigation label", stored as the
 * menu item's post_title) and a title attribute (post_excerpt / meta). We emit
 * the label and the attribute title where present.
 *
 * Only items whose label DIFFERS from the linked object's own title are truly
 * menu-owned text; but distinguishing that reliably is fragile, so we emit the
 * menu label under source_type "menu" with the menu item ID. If it duplicates
 * an entity title, the shared identity simply means one translation — harmless.
 */
class MenuSourceReader implements SourceReader
{
    public function key(): string
    {
        return 'menu';
    }

    public function isAvailable(): bool
    {
        return function_exists('wp_get_nav_menus') && function_exists('wp_get_nav_menu_items');
    }

    /**
     * Menus are enumerated, their items flattened into one ordered list, then
     * windowed by offset/limit for stable pagination.
     *
     * @return SourceString[]
     */
    public function read(int $offset, int $limit): array
    {
        $menus = wp_get_nav_menus();

        if (!is_array($menus) || $menus === []) {
            return [];
        }

        $items = [];
        foreach ($menus as $menu) {
            $menuItems = wp_get_nav_menu_items($menu->term_id);
            if (is_array($menuItems)) {
                foreach ($menuItems as $item) {
                    $items[] = $item;
                }
            }
        }

        // Stable order, then window.
        usort($items, static fn($a, $b) => ((int) $a->ID) <=> ((int) $b->ID));
        $window = array_slice($items, $offset, $limit);

        $strings = [];

        foreach ($window as $item) {
            $id = (int) $item->ID;

            $label = (string) ($item->title ?? '');
            if ($label !== '') {
                $strings[] = new SourceString($id, TranslationSourceType::MENU, 'title', $label);
            }

            $attrTitle = (string) ($item->attr_title ?? '');
            if ($attrTitle !== '') {
                $strings[] = new SourceString($id, TranslationSourceType::MENU, 'attr_title', $attrTitle);
            }

            $description = (string) ($item->description ?? '');
            if ($description !== '') {
                $strings[] = new SourceString($id, TranslationSourceType::MENU, 'description', $description);
            }
        }

        return $strings;
    }
}
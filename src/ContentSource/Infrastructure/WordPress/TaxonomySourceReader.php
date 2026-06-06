<?php

namespace TransLangs\ContentSource\Infrastructure\WordPress;

use TransLangs\ContentSource\Domain\SourceReader;
use TransLangs\ContentSource\Domain\SourceRoute;
use TransLangs\ContentSource\Domain\SourceString;

/**
 * Reads translatable text from taxonomy terms (categories, tags, and custom
 * taxonomies).
 *
 * Routing:
 *   - name        → terms, field 'name',        parentId = term_taxonomy_id
 *   - description → terms, field 'description', parentId = term_taxonomy_id
 *
 * term_taxonomy_id is used (not term_id) because the FK in
 * wp_tl_translations_terms references wp_term_taxonomy.term_taxonomy_id.
 * The field value is plain ('name', 'description') — no taxonomy-prefixed context.
 */
class TaxonomySourceReader implements SourceReader
{
    public function key(): string
    {
        return 'taxonomy';
    }

    public function isAvailable(): bool
    {
        return function_exists('get_taxonomies') && function_exists('get_terms');
    }

    /** @return SourceString[] */
    public function read(int $offset, int $limit): array
    {
        $taxonomies = get_taxonomies(['public' => true], 'names');

        if ($taxonomies === []) {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => array_values($taxonomies),
            'hide_empty' => false,
            'number'     => $limit,
            'offset'     => $offset,
            'orderby'    => 'term_id',
            'order'      => 'ASC',
        ]);

        if (!is_array($terms)) {
            return [];
        }

        $strings = [];

        foreach ($terms as $term) {
            if (!$term instanceof \WP_Term) {
                continue;
            }

            $ttId = (int) $term->term_taxonomy_id;

            if ((string) $term->name !== '') {
                $strings[] = new SourceString(
                    routeTable: SourceRoute::Terms,
                    parentId:   $ttId,
                    field:      'name',
                    metaKey:    null,
                    context:    null,
                    source:     null,
                    reference:  null,
                    text:       (string) $term->name,
                );
            }

            if ((string) $term->description !== '') {
                $strings[] = new SourceString(
                    routeTable: SourceRoute::Terms,
                    parentId:   $ttId,
                    field:      'description',
                    metaKey:    null,
                    context:    null,
                    source:     null,
                    reference:  null,
                    text:       (string) $term->description,
                );
            }
        }

        return $strings;
    }
}

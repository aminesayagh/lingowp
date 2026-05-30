<?php

namespace TransLangs\ContentSource\Infrastructure\WordPress;

use TransLangs\ContentSource\Domain\SourceReader;
use TransLangs\ContentSource\Domain\SourceString;
use TransLangs\TranslationMemory\Domain\TranslationSourceType;

/**
 * Reads translatable text from taxonomy terms (categories, tags, product
 * categories, attributes, custom taxonomies).
 *
 * For each term:
 *   - name        → context "{taxonomy}.name"        (e.g. "category.name")
 *   - description → context "{taxonomy}.description"
 *
 * The taxonomy is encoded into the context so a product category named "Shoes"
 * and a post tag named "Shoes" stay distinct identities. source_type is the
 * generic "taxonomy"; the specific taxonomy lives in the context.
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

    /**
     * Terms are read across all public taxonomies as one flat, paginated list
     * ordered by term_id, so offset/limit stay stable across the whole set.
     *
     * @return SourceString[]
     */
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

            $taxonomy = (string) $term->taxonomy;
            $termId   = (int) $term->term_id;

            if ((string) $term->name !== '') {
                $strings[] = new SourceString(
                    $termId,
                    TranslationSourceType::TAXONOMY,
                    $taxonomy . '.name',
                    (string) $term->name
                );
            }

            if ((string) $term->description !== '') {
                $strings[] = new SourceString(
                    $termId,
                    TranslationSourceType::TAXONOMY,
                    $taxonomy . '.description',
                    (string) $term->description
                );
            }
        }

        return $strings;
    }
}
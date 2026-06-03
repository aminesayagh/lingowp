<?php

namespace TransLangs\ContentSource\Infrastructure\WordPress;

use TransLangs\ContentSource\Domain\SourceReader;
use TransLangs\ContentSource\Domain\SourceString;
use TransLangs\TranslationMemory\Domain\TranslationSourceContext;
use TransLangs\TranslationMemory\Domain\TranslationSourceType;

/**
 * Reads WooCommerce product-owned text.
 *
 * Referenced text remains owned elsewhere: product categories/tags/global
 * attribute terms are taxonomy-owned, and image alt/caption/title/description
 * is media-owned.
 */
class WooCommerceProductSourceReader implements SourceReader
{
    public function key(): string
    {
        return 'woocommerce:product';
    }

    public function isAvailable(): bool
    {
        return function_exists('get_posts')
            && function_exists('post_type_exists')
            && post_type_exists('product')
            && function_exists('wc_get_product');
    }

    /**
     * @return SourceString[]
     */
    public function read(int $offset, int $limit): array
    {
        $posts = get_posts([
            'post_type'        => 'product',
            'post_status'      => 'publish',
            'posts_per_page'   => $limit,
            'offset'           => $offset,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);

        $strings = [];

        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $id = (int) $post->ID;

            foreach ($this->ownedFields($post) as $context => $value) {
                if ($value !== '') {
                    $strings[] = new SourceString($id, TranslationSourceType::PRODUCT, $context, $value);
                }
            }
        }

        return $strings;
    }

    /**
     * @return array<string, string> context => value
     */
    private function ownedFields(\WP_Post $post): array
    {
        $fields = [
            TranslationSourceContext::POST_TITLE   => (string) $post->post_title,
            TranslationSourceContext::POST_CONTENT => (string) $post->post_content,
            TranslationSourceContext::POST_EXCERPT => (string) $post->post_excerpt,
        ];

        $product = wc_get_product($post->ID);

        if (!is_object($product)) {
            return $fields;
        }

        $purchaseNote = $this->methodString($product, 'get_purchase_note');
        if ($purchaseNote !== '') {
            $fields[TranslationSourceContext::PRODUCT_PURCHASE_NOTE] = $purchaseNote;
        }

        if ($this->productIsType($product, 'external')) {
            $buttonText = $this->methodString($product, 'get_button_text');
            if ($buttonText !== '') {
                $fields[TranslationSourceContext::PRODUCT_EXTERNAL_BUTTON_TEXT] = $buttonText;
            }
        }

        return $fields + $this->customAttributeFields($product);
    }

    /**
     * Product-specific custom attributes are owned by the product. Taxonomy
     * attributes are skipped because their labels/terms are taxonomy-owned.
     *
     * @param object $product
     * @return array<string, string>
     */
    private function customAttributeFields(object $product): array
    {
        if (!method_exists($product, 'get_attributes')) {
            return [];
        }

        $attributes = $product->get_attributes();

        if (!is_array($attributes) || $attributes === []) {
            return [];
        }

        $fields = [];

        foreach ($attributes as $attributeKey => $attribute) {
            if (!is_object($attribute)) {
                continue;
            }

            if (method_exists($attribute, 'is_taxonomy') && $attribute->is_taxonomy()) {
                continue;
            }

            $contextKey = $this->contextKey((string) $attributeKey);
            $name       = $this->methodString($attribute, 'get_name');

            if ($name !== '') {
                $fields[TranslationSourceContext::attributeName($contextKey)] = $name;
            }

            foreach ($this->attributeOptions($attribute) as $index => $option) {
                if ($option === '') {
                    continue;
                }

                $fields[TranslationSourceContext::attributeOption($contextKey, $index)] = $option;
            }
        }

        return $fields;
    }

    /**
     * @param object $attribute
     * @return string[]
     */
    private function attributeOptions(object $attribute): array
    {
        if (!method_exists($attribute, 'get_options')) {
            return [];
        }

        $options = $attribute->get_options();

        if (!is_array($options)) {
            return [];
        }

        return array_values(array_map(
            static fn($option): string => trim((string) $option),
            $options
        ));
    }

    private function methodString(object $object, string $method): string
    {
        if (!method_exists($object, $method)) {
            return '';
        }

        $value = $object->{$method}();

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function productIsType(object $product, string $type): bool
    {
        if (!method_exists($product, 'is_type')) {
            return false;
        }

        return (bool) $product->is_type($type);
    }

    private function contextKey(string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            return 'custom';
        }

        if (function_exists('sanitize_key')) {
            $key = sanitize_key($key);
        } else {
            $key = strtolower((string) preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $key));
        }

        return $key !== '' ? $key : 'custom';
    }
}

<?php

declare(strict_types=1);

namespace TAW\Core\Loop;

use TAW\Core\Bindings\BindingContext;
use TAW\Core\Bindings\FieldResolver;
use TAW\Core\Bindings\Reference;
use TAW\Core\Fields\Row;
use TAW\Core\Fields\Value;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Where a TAW Loop's items come from (ADR-0014). A source is the `source`
 * attribute of `taw/loop`:
 *
 *   {"type": "repeater", "field": "book_awards", "from": "post"}
 *   {"type": "related",  "field": "book_series"}
 *   {"type": "images",   "field": "book_gallery"}
 *   {"type": "terms",    "taxonomy": "genre", "scope": "post"}
 *   {"type": "query",    "postType": "book", "terms": {"genre": "current"}, "excludeCurrent": true, "orderBy": "date"}
 *
 * `from` is post (the context post: in a post item, that post), option, term,
 * user, or row (the enclosing loop item's own field: nested loops). Field
 * sources read through FieldResolver, so a field's privacy applies to its
 * rows. Every source returns at most $max items.
 */
final class Sources
{
    public const TYPES = ['repeater', 'related', 'images', 'terms', 'query'];

    public const FROM = ['post', 'option', 'term', 'user', 'row'];

    /** Query orderings done by the database (else: the loop's own order). */
    public const QUERY_ORDER_BY = ['date', 'modified', 'title', 'menu_order', 'rand', 'comment_count', 'ID'];

    /**
     * @param array<string, mixed> $source
     * @return list<Item>
     */
    public static function items(array $source, BindingContext $context, int $max): array
    {
        $type = $source['type'] ?? null;
        if ($max <= 0 || !in_array($type, self::TYPES, true)) {
            return [];
        }

        $items = match ($type) {
            'repeater' => self::repeater($source, $context),
            'related'  => self::related($source, $context),
            'images'   => self::images($source, $context),
            'terms'    => self::terms($source, $context, $max),
            default    => self::query($source, $context, $max),
        };

        return array_slice($items, 0, $max);
    }

    /**
     * A field's value for a field source, or null (unknown, private, opted out).
     *
     * @param array<string, mixed> $source
     */
    private static function field(array $source, BindingContext $context): ?Value
    {
        $field = is_string($source['field'] ?? null) ? trim($source['field']) : '';
        $from  = in_array($source['from'] ?? 'post', self::FROM, true) ? ($source['from'] ?? 'post') : 'post';
        if ($field === '') {
            return null;
        }

        if ($from === 'row') {
            $row = $context->item?->data;
            if (!$row instanceof Row) {
                return null;
            }
            $value = $row->field($field);
            return $value->type() !== null && FieldResolver::bindable($value, null, 'post') ? $value : null;
        }

        $ref = Reference::fromArgs(['field' => $field, 'from' => $from]);

        return $ref === null ? null : (new FieldResolver())->value($ref, $context);
    }

    /**
     * @param array<string, mixed> $source
     * @return list<Item>
     */
    private static function repeater(array $source, BindingContext $context): array
    {
        $value = self::field($source, $context);
        if ($value === null || $value->type() !== 'repeater') {
            return [];
        }

        $items = [];
        foreach ($value->rows() as $row) {
            $items[] = Item::row($row, $context->postId);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $source
     * @return list<Item>
     */
    private static function related(array $source, BindingContext $context): array
    {
        $value = self::field($source, $context);
        if ($value === null || $value->type() !== 'post_select') {
            return [];
        }

        $items = [];
        foreach ($value->posts() as $ref) {
            // Only posts the visitor may read.
            if (FieldResolver::readablePost($ref->id()) !== null) {
                $items[] = Item::post($ref->id());
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $source
     * @return list<Item>
     */
    private static function images(array $source, BindingContext $context): array
    {
        $value = self::field($source, $context);
        if ($value === null || !in_array($value->type(), ['files', 'image'], true)) {
            return [];
        }

        $items = [];
        foreach ($value->images() as $image) {
            if ($image->exists() && wp_attachment_is_image($image->id())) {
                $items[] = Item::image($image->id(), $context->postId);
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $source
     * @return list<Item>
     */
    private static function terms(array $source, BindingContext $context, int $max): array
    {
        $taxonomy = is_string($source['taxonomy'] ?? null) ? $source['taxonomy'] : '';
        if ($taxonomy === '' || !taxonomy_exists($taxonomy) || !is_taxonomy_viewable($taxonomy)) {
            return [];
        }

        if (($source['scope'] ?? 'post') === 'post') {
            $postId = $context->item?->kind === Item::POST ? $context->item->postId : $context->postId;
            if (FieldResolver::readablePost($postId) === null) {
                return [];
            }
            $terms = get_the_terms($postId, $taxonomy);
        } else {
            $orderBy = in_array($source['orderBy'] ?? 'name', ['name', 'count', 'slug', 'term_order', 'id'], true) ? $source['orderBy'] ?? 'name' : 'name';
            $terms = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => ($source['hideEmpty'] ?? true) !== false,
                'orderby'    => $orderBy === 'id' ? 'term_id' : $orderBy,
                'order'      => strtoupper((string) ($source['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC',
                'parent'     => isset($source['parent']) && is_numeric($source['parent']) ? (int) $source['parent'] : '',
                'number'     => $max,
            ]);
        }

        if (!is_array($terms)) {
            return [];
        }

        $items = [];
        foreach ($terms as $term) {
            $items[] = Item::term($term, $context->postId);
        }

        return $items;
    }

    /**
     * Published posts only (what any visitor may read), never more than $max.
     *
     * @param array<string, mixed> $source
     * @return list<Item>
     */
    private static function query(array $source, BindingContext $context, int $max): array
    {
        $args = self::queryArgs($source, $context, $max);
        if ($args === null) {
            return [];
        }

        $items = [];
        foreach ((new \WP_Query($args))->posts as $id) {
            $items[] = Item::post((int) $id);
        }

        return $items;
    }

    /**
     * The WP_Query args for a query source, or null when it can't run (an
     * unknown or non-public post type).
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>|null
     */
    public static function queryArgs(array $source, BindingContext $context, int $max): ?array
    {
        $types = array_values(array_filter(
            (array) ($source['postType'] ?? 'post'),
            static fn ($type): bool => is_string($type) && post_type_exists($type) && is_post_type_viewable($type)
        ));
        if ($types === []) {
            return null;
        }

        $orderBy = $source['orderBy'] ?? 'date';
        $args = [
            'post_type'           => $types,
            'post_status'         => 'publish',
            'posts_per_page'      => $max,
            'fields'              => 'ids',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'has_password'        => false,
            'orderby'             => in_array($orderBy, self::QUERY_ORDER_BY, true) ? $orderBy : 'date',
            'order'               => strtoupper((string) ($source['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
            'suppress_filters'    => false,
        ];

        // Terms: {"genre": [3, 4]} or {"genre": "current"} (the context post's genres).
        $taxQuery = [];
        foreach (is_array($source['terms'] ?? null) ? $source['terms'] : [] as $taxonomy => $terms) {
            if (!is_string($taxonomy) || !taxonomy_exists($taxonomy)) {
                continue;
            }
            if ($terms === 'current') {
                $current = get_the_terms($context->postId, $taxonomy);
                $terms = is_array($current) ? wp_list_pluck($current, 'term_id') : [];
                if ($terms === []) {
                    return null;
                }
            }
            $ids = array_values(array_filter(array_map('intval', (array) $terms)));
            if ($ids !== []) {
                $taxQuery[] = ['taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $ids];
            }
        }
        if ($taxQuery !== []) {
            $args['tax_query'] = count($taxQuery) > 1 ? ['relation' => 'AND', ...$taxQuery] : $taxQuery;
        }

        $author = $source['author'] ?? null;
        if ($author === 'current') {
            $post = get_post($context->postId);
            $author = $post instanceof \WP_Post ? (int) $post->post_author : null;
        }
        if (is_numeric($author) && (int) $author > 0) {
            $args['author'] = (int) $author;
        }

        if (is_string($source['search'] ?? null) && trim($source['search']) !== '') {
            $args['s'] = trim($source['search']);
        }

        $include = array_values(array_filter(array_map('intval', (array) ($source['include'] ?? []))));
        if ($include !== []) {
            $args['post__in'] = $include;
        }
        $exclude = array_values(array_filter(array_map('intval', (array) ($source['exclude'] ?? []))));
        if (($source['excludeCurrent'] ?? false) === true && $context->postId > 0) {
            $exclude[] = $context->postId;
        }
        if ($exclude !== []) {
            $args['post__not_in'] = $exclude;
        }

        $sticky = $source['sticky'] ?? '';
        if ($sticky === 'only' || $sticky === 'exclude') {
            $ids = array_map('intval', (array) get_option('sticky_posts', []));
            if ($sticky === 'only') {
                $args['post__in'] = $ids === [] ? [0] : $ids;
            } else {
                $args['post__not_in'] = [...($args['post__not_in'] ?? []), ...$ids];
            }
        }

        return $args;
    }
}

<?php

declare(strict_types=1);

namespace TAW\Core\Loop;

use TAW\Core\Bindings\BindingContext;
use TAW\Core\Bindings\Conditions;
use TAW\Core\Bindings\Expression\Evaluator;
use TAW\Helpers\Framework;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The TAW Loop blocks (ADR-0014), rendered on the server:
 *
 *   taw/loop
 *     ├─ (any blocks: a heading…)       rendered once
 *     ├─ taw/loop-item                  rendered once per item, with the item as context
 *     ├─ taw/loop-empty                 rendered when there are no items
 *     └─ taw/loop-pagination            links to the loop's other pages
 *
 * The loop reads its items from a source (Sources), then filters them with a
 * condition (ADR-0013), orders, offsets, limits (never more than MAX_ITEMS)
 * and pages them. Each item's blocks get `taw/loopItem` (and, for posts,
 * `postId`/`postType`), so `@row.*`, `@loop.*`, chips, bindings and
 * conditions read that item.
 */
final class Loop
{
    public const MAX_ITEMS = 200;

    public const MAX_DEPTH = 3;

    /** The page of loop N: ?taw-loop-N=2. */
    public const PAGE_PARAM = 'taw-loop-';

    public const BLOCKS = ['loop', 'loop-item', 'loop-empty', 'loop-pagination'];

    /** Context keys the loop hands to its inner blocks. */
    private const TAG = 'taw/loopTag';

    private const PAGES = 'taw/loopPages';

    public const STYLE = 'taw-loop';

    private const CSS = '.taw-loop__items{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:var(--taw-loop-gap,1.5rem)}'
        . '.taw-loop__items>li{margin:0}'
        . '.taw-loop__items.is-grid{display:grid;grid-template-columns:repeat(var(--taw-loop-columns,3),minmax(0,1fr))}'
        . '@media (max-width:781px){.taw-loop__items.is-grid{grid-template-columns:repeat(min(2,var(--taw-loop-columns,3)),minmax(0,1fr))}}'
        . '@media (max-width:480px){.taw-loop__items.is-grid{grid-template-columns:minmax(0,1fr)}}'
        . '.wp-block-taw-loop-pagination{display:flex;flex-wrap:wrap;gap:.5em;align-items:center;margin-top:1.5rem}'
        . '.taw-loop-pagination__number.is-current{font-weight:600}';

    private static bool $registered = false;

    private static int $depth = 0;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_action('init', [self::class, 'registerBlocks']);
        add_filter('register_block_type_args', [self::class, 'addContext'], 10, 2);
    }

    public static function registerBlocks(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }
        $callbacks = [
            'loop'            => [self::class, 'renderLoop'],
            'loop-item'       => [self::class, 'renderItem'],
            'loop-empty'      => [self::class, 'renderEmpty'],
            'loop-pagination' => [self::class, 'renderPagination'],
        ];
        // The grid's CSS, inline: block.json file assets break when the theme is a symlink.
        wp_register_style(self::STYLE, false, [], Framework::version());
        wp_add_inline_style(self::STYLE, self::CSS);

        foreach ($callbacks as $block => $callback) {
            $args = ['render_callback' => $callback];
            if ($block === 'loop' || $block === 'loop-pagination') {
                $args['style_handles'] = [self::STYLE];
            }
            register_block_type(Framework::path("resources/blocks/{$block}"), $args);
        }
    }

    /**
     * register_block_type_args: every block may sit inside a loop item, so every
     * block receives the item and its post (chips, bindings and conditions on
     * any block read them). Only adds context; it changes no output by itself.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function addContext(array $args, string $name = ''): array
    {
        $uses = is_array($args['uses_context'] ?? null) ? $args['uses_context'] : [];
        $args['uses_context'] = array_values(array_unique([...$uses, BindingContext::ITEM, 'postId', 'postType']));

        return $args;
    }

    /**
     * taw/loop's render_callback.
     *
     * @param array<string, mixed> $attributes
     */
    public static function renderLoop(array $attributes, string $content = '', ?object $block = null): string
    {
        if ($block === null || self::$depth >= self::MAX_DEPTH || !property_exists($block, 'parsed_block')) {
            return '';
        }

        self::$depth++;
        try {
            return self::loop($attributes, $block);
        } finally {
            self::$depth--;
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function loop(array $attributes, object $block): string
    {
        $context = BindingContext::fromBlock($block);
        $plan    = self::items($attributes, $context);
        $inner   = is_array($block->parsed_block['innerBlocks'] ?? null) ? $block->parsed_block['innerBlocks'] : [];
        $base    = self::baseContext($block);
        $tag     = ($attributes['layout']['type'] ?? 'list') === 'list' && ($attributes['ordered'] ?? false) === true ? 'ol' : 'ul';

        $html = '';
        foreach ($inner as $child) {
            $name = $child['blockName'] ?? '';
            if ($name === 'taw/loop-item') {
                if ($plan['items'] === []) {
                    continue;
                }
                $list = '';
                foreach ($plan['items'] as $item) {
                    $list .= self::withPost($item, static fn (): string => self::renderChild($child, array_merge($base, self::itemContext($item), [self::TAG => 'li'])));
                }
                $html .= sprintf('<%1$s class="%2$s"%3$s>%4$s</%1$s>', $tag, esc_attr(self::listClass($attributes)), self::listStyle($attributes), $list);
            } elseif ($name === 'taw/loop-empty') {
                $html .= $plan['items'] === [] ? self::renderChild($child, array_merge($base, ['taw/loopEmpty' => true])) : '';
            } elseif ($name === 'taw/loop-pagination') {
                $html .= self::renderChild($child, array_merge($base, [self::PAGES => $plan['pages']]));
            } else {
                $html .= self::renderChild($child, $base);
            }
        }

        if (!function_exists('get_block_wrapper_attributes')) {
            return '<div class="wp-block-taw-loop">' . $html . '</div>';
        }

        return sprintf('<div %s>%s</div>', get_block_wrapper_attributes(['class' => 'taw-loop']), $html);
    }

    /**
     * The loop's items for this page, and its paging: source → filter → order
     * → offset → limit (≤ MAX_ITEMS) → page. Indexes count within the list
     * after limiting; `count` is that list's length.
     *
     * @param array<string, mixed> $attributes
     * @return array{items: list<Item>, pages: array{current: int, total: int, param: string}}
     */
    public static function items(array $attributes, BindingContext $context): array
    {
        $source = is_array($attributes['source'] ?? null) ? $attributes['source'] : [];
        $items  = Sources::items($source, $context, self::MAX_ITEMS);

        $filter = $attributes['filter'] ?? null;
        if (is_array($filter) && ($filter['rules'] ?? []) !== []) {
            $count    = count($items);
            $position = 0;
            $items = array_values(array_filter($items, static function (Item $item) use ($filter, $context, $count, &$position): bool {
                $item->index = ++$position;
                $item->count = $count;
                return Conditions::shown($filter, $context->forItem($item));
            }));
        }

        $items = self::order($items, is_array($attributes['order'] ?? null) ? $attributes['order'] : [], $context);

        $offset = max(0, (int) ($attributes['offset'] ?? 0));
        $limit  = (int) ($attributes['limit'] ?? 0);
        $items  = array_slice($items, $offset, $limit > 0 ? min($limit, self::MAX_ITEMS) : self::MAX_ITEMS);

        $total = count($items);
        foreach ($items as $i => $item) {
            $item->index = $i + 1;
            $item->count = $total;
        }

        $param   = self::PAGE_PARAM . max(0, (int) ($attributes['loopId'] ?? 0));
        $perPage = max(0, (int) ($attributes['perPage'] ?? 0));
        $pages   = ['current' => 1, 'total' => 1, 'param' => $param];
        if ($perPage > 0 && $total > $perPage) {
            $pages['total']   = (int) ceil($total / $perPage);
            $pages['current'] = min($pages['total'], max(1, self::requestedPage($param)));
            $items = array_slice($items, ($pages['current'] - 1) * $perPage, $perPage);
        }

        return ['items' => $items, 'pages' => $pages];
    }

    /**
     * Order items by a value: {"by": "@row.year", "dir": "desc", "as": "number"}
     * (`as`: text, number or date). "random" shuffles. No `by`: the source's order.
     *
     * @param list<Item>           $items
     * @param array<string, mixed> $order
     * @return list<Item>
     */
    private static function order(array $items, array $order, BindingContext $context): array
    {
        $by = is_string($order['by'] ?? null) ? trim($order['by']) : '';
        if ($by === 'random') {
            shuffle($items);
            return $items;
        }
        if (!str_starts_with($by, '@')) {
            // The source's own order; "desc" reverses it.
            return strtolower((string) ($order['dir'] ?? 'asc')) === 'desc' ? array_reverse($items) : $items;
        }

        $as   = in_array($order['as'] ?? 'text', ['text', 'number', 'date'], true) ? $order['as'] ?? 'text' : 'text';
        $keys = [];
        foreach ($items as $i => $item) {
            $text = Evaluator::tokenValue($by, $context->forItem($item), $as === 'date' ? Conditions::DATE_FORMAT : null);
            $keys[$i] = match ($as) {
                'number' => is_numeric(trim($text)) ? (float) $text : null,
                'date'   => ($time = strtotime($text)) === false ? null : $time,
                default  => mb_strtolower(trim($text), 'UTF-8'),
            };
        }

        $desc = strtolower((string) ($order['dir'] ?? 'asc')) === 'desc';
        $ids  = array_keys($items);
        usort($ids, static function (int $a, int $b) use ($keys, $desc): int {
            // Empty values go last either way; ties keep the source order.
            if ($keys[$a] === null || $keys[$a] === '' || $keys[$b] === null || $keys[$b] === '') {
                return ($keys[$a] === null || $keys[$a] === '') <=> ($keys[$b] === null || $keys[$b] === '') ?: $a <=> $b;
            }
            $cmp = $keys[$a] <=> $keys[$b];
            return ($desc ? -$cmp : $cmp) ?: $a <=> $b;
        });

        return array_map(static fn (int $i): Item => $items[$i], $ids);
    }

    /**
     * Runs $render with a post item's post as the global post, as core's Post
     * Template does: core blocks (Post Title, Excerpt…) read the global post.
     * The previous post comes back afterwards, so nested loops unwind cleanly.
     *
     * @param callable(): string $render
     */
    private static function withPost(Item $item, callable $render): string
    {
        $post = $item->kind === Item::POST ? get_post($item->postId) : null;
        if (!$post instanceof \WP_Post) {
            return $render();
        }

        $previous = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);
        try {
            return $render();
        } finally {
            $GLOBALS['post'] = $previous;
            if ($previous instanceof \WP_Post) {
                setup_postdata($previous);
            }
        }
    }

    /**
     * The context for one item's blocks.
     *
     * @return array<string, mixed>
     */
    private static function itemContext(Item $item): array
    {
        $context = [BindingContext::ITEM => $item];
        if ($item->kind === Item::POST) {
            $post = get_post($item->postId);
            $context['postId']   = $item->postId;
            $context['postType'] = $post instanceof \WP_Post ? $post->post_type : 'post';
        }

        return $context;
    }

    /**
     * What the loop passes on from its own context (the post, an outer item).
     *
     * @return array<string, mixed>
     */
    private static function baseContext(object $block): array
    {
        $context = property_exists($block, 'context') && is_array($block->context) ? $block->context : [];

        return array_intersect_key($context, array_flip(['postId', 'postType', BindingContext::ITEM, 'queryId', 'query']));
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $context
     */
    private static function renderChild(array $parsed, array $context): string
    {
        return (new \WP_Block($parsed, $context))->render();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function listClass(array $attributes): string
    {
        $layout = is_array($attributes['layout'] ?? null) ? $attributes['layout'] : [];

        return ($layout['type'] ?? 'list') === 'grid' ? 'taw-loop__items is-grid' : 'taw-loop__items';
    }

    /**
     * Grid columns and the gap as CSS variables (the block's style.css uses them).
     *
     * @param array<string, mixed> $attributes
     */
    private static function listStyle(array $attributes): string
    {
        $layout  = is_array($attributes['layout'] ?? null) ? $attributes['layout'] : [];
        $columns = max(1, min(6, (int) ($layout['columns'] ?? 3)));
        $gap     = is_string($layout['gap'] ?? null) && preg_match('/^\d+(\.\d+)?(px|rem|em|%)$/', $layout['gap']) === 1 ? $layout['gap'] : '';
        $style   = ($layout['type'] ?? 'list') === 'grid' ? "--taw-loop-columns:{$columns};" : '';
        $style  .= $gap !== '' ? "--taw-loop-gap:{$gap};" : '';

        return $style === '' ? '' : ' style="' . esc_attr($style) . '"';
    }

    private static function requestedPage(string $param): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public page number.
        return isset($_GET[$param]) && is_numeric($_GET[$param]) ? (int) $_GET[$param] : 1;
    }

    /**
     * taw/loop-item: one item's blocks, in the loop's item element. Outside a
     * loop (no item in context) it renders nothing.
     *
     * @param array<string, mixed> $attributes
     */
    public static function renderItem(array $attributes, string $content = '', ?object $block = null): string
    {
        $context = $block !== null && property_exists($block, 'context') && is_array($block->context) ? $block->context : [];
        if (!($context[BindingContext::ITEM] ?? null) instanceof Item) {
            return '';
        }
        $tag     = ($context[self::TAG] ?? 'li') === 'div' ? 'div' : 'li';
        $wrapper = function_exists('get_block_wrapper_attributes') ? get_block_wrapper_attributes() : 'class="wp-block-taw-loop-item"';

        return sprintf('<%1$s %2$s>%3$s</%1$s>', $tag, $wrapper, $content);
    }

    /**
     * taw/loop-empty: its blocks, only when the loop has no items.
     *
     * @param array<string, mixed> $attributes
     */
    public static function renderEmpty(array $attributes, string $content = '', ?object $block = null): string
    {
        $context = $block !== null && property_exists($block, 'context') && is_array($block->context) ? $block->context : [];
        if (($context['taw/loopEmpty'] ?? false) !== true) {
            return '';
        }
        $wrapper = function_exists('get_block_wrapper_attributes') ? get_block_wrapper_attributes() : 'class="wp-block-taw-loop-empty"';

        return sprintf('<div %s>%s</div>', $wrapper, $content);
    }

    /**
     * taw/loop-pagination: previous, page numbers and next, when the loop has
     * more than one page. Links keep the rest of the query string.
     *
     * @param array<string, mixed> $attributes
     */
    public static function renderPagination(array $attributes, string $content = '', ?object $block = null): string
    {
        $context = $block !== null && property_exists($block, 'context') && is_array($block->context) ? $block->context : [];
        $pages   = $context[self::PAGES] ?? null;
        if (!is_array($pages) || (int) ($pages['total'] ?? 1) <= 1) {
            return '';
        }

        $current = (int) $pages['current'];
        $total   = (int) $pages['total'];
        $param   = (string) $pages['param'];
        $link    = static fn (int $page): string => esc_url(add_query_arg($param, $page > 1 ? $page : false));

        $parts = [];
        if ($current > 1) {
            $parts[] = sprintf('<a class="taw-loop-pagination__previous" href="%s">%s</a>', $link($current - 1), esc_html((string) ($attributes['previousLabel'] ?? '') ?: __('Previous', 'taw-core')));
        }
        if (($attributes['showNumbers'] ?? true) !== false) {
            for ($page = 1; $page <= $total; $page++) {
                $parts[] = $page === $current
                    ? sprintf('<span class="taw-loop-pagination__number is-current" aria-current="page">%d</span>', $page)
                    : sprintf('<a class="taw-loop-pagination__number" href="%s">%d</a>', $link($page), $page);
            }
        }
        if ($current < $total) {
            $parts[] = sprintf('<a class="taw-loop-pagination__next" href="%s">%s</a>', $link($current + 1), esc_html((string) ($attributes['nextLabel'] ?? '') ?: __('Next', 'taw-core')));
        }

        $wrapper = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes(['aria-label' => __('Pagination', 'taw-core')])
            : 'class="wp-block-taw-loop-pagination"';

        return sprintf('<nav %s>%s</nav>', $wrapper, implode('', $parts));
    }

    /**
     * The editor's item previews (PreviewEndpoint `loop/render`): each item's
     * `taw/loop-item` rendered as on the front end, the first $max of them,
     * without paging. $content is the loop's inner blocks, as the editor has them.
     *
     * @param array<string, mixed> $attributes
     * @return array{items: list<array{index: int, html: string, postId: int, postType: string}>, total: int}
     */
    public static function renderPreview(array $attributes, string $content, BindingContext $context, int $max): array
    {
        $template = null;
        foreach (parse_blocks($content) as $block) {
            if (($block['blockName'] ?? '') === 'taw/loop-item') {
                $template = $block;
                break;
            }
        }
        $plan = self::items(array_merge($attributes, ['perPage' => 0]), $context);
        if ($template === null || self::$depth >= self::MAX_DEPTH) {
            return ['items' => [], 'total' => count($plan['items'])];
        }

        $base = ['postId' => $context->postId, 'postType' => (string) get_post_type($context->postId)];
        if ($context->item !== null) {
            $base[BindingContext::ITEM] = $context->item;
        }

        self::$depth++;
        try {
            $items = [];
            foreach (array_slice($plan['items'], 0, $max) as $item) {
                $isPost  = $item->kind === Item::POST;
                $items[] = [
                    'index'    => $item->index,
                    'html'     => self::withPost($item, static fn (): string => self::renderChild($template, array_merge($base, self::itemContext($item), [self::TAG => 'li']))),
                    // A post item's post: the editor gives the editable first item its context.
                    'postId'   => $isPost ? $item->postId : 0,
                    'postType' => $isPost ? (string) get_post_type($item->postId) : '',
                ];
            }
        } finally {
            self::$depth--;
        }

        return ['items' => $items, 'total' => count($plan['items'])];
    }

    /** @internal For tests. */
    public static function reset(): void
    {
        self::$registered = false;
        self::$depth = 0;
    }
}

<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

use TAW\Core\Bindings\Expression\Evaluator;
use TAW\Core\Loop\Loop;
use TAW\Core\Loop\RowValues;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `POST /wp-json/taw/v1/bindings/preview`: the values bound blocks show in
 * the editor canvas (ADR-0010 decision 8). It runs the same resolver the
 * front end does, so the preview is what the page will render.
 *
 * Request:  {"items": [{"key", "args", "block", "attribute", "postId", "postType"}]}
 *           An inline dynamic tag (ADR-0011) sends "kind": "tag" and no block/attribute:
 *           its value is the plain text InlineTags::value() gives (fallback included).
 *           An expression (ADR-0012) sends "kind": "expr" and args {"expr": "…"}: its value
 *           is {"value": "…", "errors": [{code, at}]}.
 *           A condition (ADR-0013) sends "kind": "condition" and args {"if": {…}}: its value
 *           is {"shown": bool, "errors": [{code, path}]}.
 *           Inside a TAW Loop (ADR-0014), "loops" lists the enclosing loops' attributes, outermost
 *           first: values then read each loop's first item (the item the editor edits), so
 *           `@row.*`, `@loop.*` and bindings with {"row": …} preview real values.
 * Response: {"values": {"<key>": <value or null>}}
 *
 * Editors only (`edit_posts`), and a post's values only for users who can
 * edit that post. Templates have no post: a post-type context previews the
 * most recent published post of that type.
 */
final class PreviewEndpoint
{
    public const NAMESPACE = 'taw/v1';

    public const ROUTE = '/bindings/preview';

    public const LOOP_ROUTE = '/loop/render';

    /** Items a loop preview renders. */
    public const PREVIEW_ITEMS = 12;

    /** A page of bound blocks, not a bulk export. */
    public const MAX_ITEMS = 200;

    public static function registerRoute(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle'],
            'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            'args'                => ['items' => ['type' => 'array', 'required' => true]],
        ]);
        // The TAW Loop's item previews (ADR-0014), with the same rules.
        register_rest_route(self::NAMESPACE, self::LOOP_ROUTE, [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleLoop'],
            'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            'args'                => ['attributes' => ['type' => 'object', 'required' => true], 'content' => ['type' => 'string', 'required' => true]],
        ]);
    }

    /**
     * `POST taw/v1/loop/render`: a TAW Loop's items rendered for the editor
     * canvas: {"items": [{"index", "html"}], "total"}. At most PREVIEW_ITEMS.
     */
    public static function handleLoop(\WP_REST_Request $request): \WP_REST_Response
    {
        $item = $request->get_params();
        $postId = self::postId($item);
        if ($postId === null) {
            return new \WP_REST_Response(['items' => [], 'total' => 0], 403);
        }
        $attributes = $request->get_param('attributes');
        $content = $request->get_param('content');

        return new \WP_REST_Response(Loop::renderPreview(
            is_array($attributes) ? $attributes : [],
            is_string($content) ? $content : '',
            self::context($item, $postId),
            self::PREVIEW_ITEMS
        ));
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $items  = $request->get_param('items');
        $values = [];

        foreach (array_slice(is_array($items) ? $items : [], 0, self::MAX_ITEMS) as $item) {
            if (!is_array($item) || !isset($item['key']) || !is_string($item['key'])) {
                continue;
            }
            $values[$item['key']] = self::resolve($item);
        }

        return new \WP_REST_Response(['values' => (object) $values]);
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function resolve(array $item): mixed
    {
        $args = is_array($item['args'] ?? null) ? $item['args'] : [];
        $kind = $item['kind'] ?? null;
        if ($kind === 'expr') {
            return self::expression($args, $item);
        }
        if ($kind === 'condition') {
            $postId = self::postId($item);
            return $postId === null ? null : Conditions::check($args['if'] ?? null, self::context($item, $postId));
        }
        $ref = isset($args['expr']) ? null : Reference::fromArgs($args);
        $isTag = $kind === 'tag';
        // An expression bound to an attribute (ADR-0015): a link, an image's ID/URL/alt.
        if (!$isTag && isset($args['expr'])) {
            $postId = self::postId($item);
            $block = is_string($item['block'] ?? null) ? $item['block'] : '';
            $attribute = is_string($item['attribute'] ?? null) ? $item['attribute'] : '';
            if ($postId === null || $block === '' || $attribute === '') {
                return null;
            }
            return Bindings::expressionValue($args, self::context($item, $postId), Target::for($block, $attribute, self::attributeSchema($block, $attribute)));
        }
        if ($isTag && (isset($args['expr']) || isset($args['row']) || isset($args['loop']))) {
            $postId = self::postId($item);
            return $postId === null ? null : InlineTags::value($args, self::context($item, $postId));
        }
        // A loop item's value bound to an attribute: {"row": "photo"}.
        if (!$isTag && (isset($args['row']) || isset($args['loop']))) {
            $postId = self::postId($item);
            $block = is_string($item['block'] ?? null) ? $item['block'] : '';
            $attribute = is_string($item['attribute'] ?? null) ? $item['attribute'] : '';
            if ($postId === null || $block === '' || $attribute === '') {
                return null;
            }
            $value = RowValues::forTarget($args, self::context($item, $postId), Target::for($block, $attribute, self::attributeSchema($block, $attribute)));
            return $value === false ? '' : $value;
        }
        $block = is_string($item['block'] ?? null) ? $item['block'] : '';
        $attribute = is_string($item['attribute'] ?? null) ? $item['attribute'] : '';
        if ($ref === null || (!$isTag && ($block === '' || $attribute === '' || $ref->tag !== null))) {
            return null;
        }

        $postId = self::postId($item);
        if ($postId === null) {
            return null;
        }

        if ($isTag) {
            return InlineTags::value($args, self::context($item, $postId));
        }

        $value = Bindings::resolver()->resolve($ref, self::context($item, $postId), Target::for($block, $attribute, self::attributeSchema($block, $attribute)));

        return $value === false ? '' : $value;
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $item
     * @return array{value: string, errors: list<array{code: string, at: int}>}|null
     */
    private static function expression(array $args, array $item): ?array
    {
        $postId = self::postId($item);
        if ($postId === null || !is_string($args['expr'] ?? null)) {
            return null;
        }

        return Evaluator::evaluate($args['expr'], self::context($item, $postId));
    }

    /**
     * The context a preview reads: the post, then, inside TAW Loops, each
     * enclosing loop's first item (outermost first).
     *
     * @param array<string, mixed> $item
     */
    private static function context(array $item, int $postId): BindingContext
    {
        $context = new BindingContext($postId);
        $loops = is_array($item['loops'] ?? null) ? array_slice(array_values($item['loops']), 0, Loop::MAX_DEPTH) : [];
        foreach ($loops as $attributes) {
            $first = is_array($attributes) ? (Loop::items(array_merge($attributes, ['perPage' => 0]), $context)['items'][0] ?? null) : null;
            if ($first === null) {
                break;
            }
            $context = $context->forItem($first);
        }

        return $context;
    }

    /**
     * The post an item previews: its own (only for users who can edit it), or
     * for a template the latest post of its type. Null when refused.
     *
     * @param array<string, mixed> $item
     */
    private static function postId(array $item): ?int
    {
        $postId = is_numeric($item['postId'] ?? null) ? (int) $item['postId'] : 0;
        if ($postId > 0 && !current_user_can('edit_post', $postId)) {
            return null;
        }
        if ($postId <= 0 && is_string($item['postType'] ?? null) && $item['postType'] !== '') {
            $postId = self::samplePost($item['postType']);
        }

        return $postId;
    }

    private static function samplePost(string $postType): int
    {
        $ids = get_posts(['post_type' => $postType, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => false]);

        return isset($ids[0]) ? (int) $ids[0] : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function attributeSchema(string $block, string $attribute): ?array
    {
        if (!class_exists('WP_Block_Type_Registry')) {
            return null;
        }
        $type = \WP_Block_Type_Registry::get_instance()->get_registered($block);
        $schema = $type !== null && is_array($type->attributes) ? ($type->attributes[$attribute] ?? null) : null;

        return is_array($schema) ? $schema : null;
    }
}

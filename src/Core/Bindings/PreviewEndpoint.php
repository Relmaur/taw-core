<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

use TAW\Core\Bindings\Expression\Evaluator;

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
        $ref = isset($args['expr']) ? null : Reference::fromArgs($args);
        $isTag = $kind === 'tag';
        if ($isTag && isset($args['expr'])) {
            $postId = self::postId($item);
            return $postId === null ? null : InlineTags::value($args, new BindingContext($postId));
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
            return InlineTags::value($args, new BindingContext($postId));
        }

        $value = Bindings::resolver()->resolve($ref, new BindingContext($postId), Target::for($block, $attribute, self::attributeSchema($block, $attribute)));

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

        return Evaluator::evaluate($args['expr'], new BindingContext($postId));
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

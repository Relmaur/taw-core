<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

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
        $ref = Reference::fromArgs($args);
        $isTag = ($item['kind'] ?? null) === 'tag';
        $block = is_string($item['block'] ?? null) ? $item['block'] : '';
        $attribute = is_string($item['attribute'] ?? null) ? $item['attribute'] : '';
        if ($ref === null || (!$isTag && ($block === '' || $attribute === '' || $ref->tag !== null))) {
            return null;
        }

        $postId = is_numeric($item['postId'] ?? null) ? (int) $item['postId'] : 0;
        if ($postId > 0 && !current_user_can('edit_post', $postId)) {
            return null;
        }
        if ($postId <= 0 && is_string($item['postType'] ?? null) && $item['postType'] !== '') {
            $postId = self::samplePost($item['postType']);
        }

        if ($isTag) {
            return InlineTags::value($args, new BindingContext($postId));
        }

        $value = Bindings::resolver()->resolve($ref, new BindingContext($postId), Target::for($block, $attribute, self::attributeSchema($block, $attribute)));

        return $value === false ? '' : $value;
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

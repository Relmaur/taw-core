<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

use TAW\Core\Fields\Fields;
use TAW\Core\Fields\Value;
use TAW\Taw;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A TAW field, read through the typed API (ADR-0009) and shaped for the
 * attribute by AttributeMap.
 *
 * Privacy (ADR-0010 decision 5): a post that isn't publicly viewable needs
 * `read_post`, a password-protected post gives nothing (as core/post-meta);
 * only registered fields bind; user fields need `'bindings' => true`, and
 * any other field can say `'bindings' => false`.
 */
final class FieldResolver implements ExpressionResolver
{
    public function resolve(Reference $ref, BindingContext $context, Target $target): mixed
    {
        $fields = $this->fieldsFor($ref, $context);
        if ($fields === null || !$fields->exists()) {
            return null;
        }

        $value = $fields->field($ref->field);
        $group = null;
        if ($value->type() === 'group' && $ref->sub !== null) {
            $group = $value;
            $value = $value->field($ref->sub);
        }

        if ($value->type() === null || !self::bindable($value, $group, $ref->from)) {
            return null;
        }

        return AttributeMap::valueFor($value, $target, $ref);
    }

    private function fieldsFor(Reference $ref, BindingContext $context): ?Fields
    {
        return match ($ref->from) {
            'option' => Taw::options(),
            'term'   => Taw::term(),
            'user'   => $this->author($context),
            default  => ($post = self::visiblePost($context->postId)) !== null ? Taw::post($post) : null,
        };
    }

    /** The queried author on an author archive, else the context post's author. */
    private function author(BindingContext $context): ?Fields
    {
        $queried = get_queried_object();
        if ($queried instanceof \WP_User) {
            return Taw::user($queried);
        }

        $post = self::visiblePost($context->postId);

        return $post !== null && (int) $post->post_author > 0 ? Taw::user((int) $post->post_author) : null;
    }

    private static function visiblePost(int $postId): ?\WP_Post
    {
        $post = $postId > 0 ? get_post($postId) : null;
        if (!$post instanceof \WP_Post) {
            return null;
        }
        if ((!is_post_publicly_viewable($post) && !current_user_can('read_post', $post->ID)) || post_password_required($post)) {
            return null;
        }

        return $post;
    }

    private static function bindable(Value $value, ?Value $group, string $from): bool
    {
        $flag = $value->config()['bindings'] ?? $group?->config()['bindings'] ?? null;

        return $from === 'user' ? $flag === true : $flag !== false;
    }
}

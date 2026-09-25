<?php

declare(strict_types=1);

namespace TAW\Core\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A post's TAW fields: `Taw::post($id)->field('cover')->image()`.
 */
final class PostFields extends MetaFields
{
    public function __construct(private readonly ?\WP_Post $post)
    {
    }

    public function id(): int
    {
        return $this->post !== null ? $this->post->ID : 0;
    }

    public function post(): ?\WP_Post
    {
        return $this->post;
    }

    protected function objectType(): string
    {
        return 'post';
    }

    protected function subtype(): string
    {
        return $this->post !== null ? (string) $this->post->post_type : '';
    }

    protected function read(string $key): mixed
    {
        return get_post_meta($this->id(), $key, true);
    }
}

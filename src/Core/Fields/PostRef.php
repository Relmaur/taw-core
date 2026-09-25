<?php

declare(strict_types=1);

namespace TAW\Core\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A post chosen in a post_select field (ADR-0009). A missing or deleted
 * post gives empty values and `exists()` false.
 */
final class PostRef implements \Stringable
{
    public function __construct(private readonly int $id)
    {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function post(): ?\WP_Post
    {
        $post = $this->id > 0 ? get_post($this->id) : null;

        return $post instanceof \WP_Post ? $post : null;
    }

    public function exists(): bool
    {
        return $this->post() !== null;
    }

    /** The title as WordPress displays it (filtered, not escaped). */
    public function title(): string
    {
        return $this->exists() ? (string) get_the_title($this->id) : '';
    }

    public function url(): string
    {
        return $this->exists() ? (string) (get_permalink($this->id) ?: '') : '';
    }

    public function type(): string
    {
        $post = $this->post();

        return $post !== null ? (string) $post->post_type : '';
    }

    /** The title, escaped: what `echo` prints. */
    public function html(): string
    {
        return esc_html($this->title());
    }

    /**
     * The post's TAW fields.
     */
    public function fields(): PostFields
    {
        return new PostFields($this->post());
    }

    public function __toString(): string
    {
        return $this->html();
    }
}

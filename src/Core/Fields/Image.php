<?php

declare(strict_types=1);

namespace TAW\Core\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * An attachment held by an image or files field (ADR-0009). With no
 * attachment, every method returns an empty value and `exists()` is false.
 */
final class Image implements \Stringable
{
    public function __construct(private readonly int $id)
    {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function exists(): bool
    {
        return $this->id > 0 && $this->url() !== '';
    }

    public function url(string $size = 'full'): string
    {
        return $this->id > 0 ? (string) (wp_get_attachment_image_url($this->id, $size) ?: '') : '';
    }

    public function alt(): string
    {
        return $this->id > 0 ? (string) get_post_meta($this->id, '_wp_attachment_image_alt', true) : '';
    }

    public function width(string $size = 'full'): int
    {
        return $this->id > 0 ? (int) ((wp_get_attachment_image_src($this->id, $size) ?: [])[1] ?? 0) : 0;
    }

    public function height(string $size = 'full'): int
    {
        return $this->id > 0 ? (int) ((wp_get_attachment_image_src($this->id, $size) ?: [])[2] ?? 0) : 0;
    }

    /**
     * The <img> markup (srcset, sizes, alt and lazy loading from WordPress).
     *
     * @param string|array{0: int, 1: int} $size
     * @param array<string, string>        $attributes
     */
    public function html(string|array $size = 'full', array $attributes = []): string
    {
        return $this->id > 0 ? (string) wp_get_attachment_image($this->id, $size, false, $attributes) : '';
    }

    public function __toString(): string
    {
        return $this->html();
    }
}

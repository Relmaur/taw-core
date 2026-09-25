<?php

declare(strict_types=1);

namespace TAW\Core\Metabox;

use TAW\Core\Metabox\Store\MetaStore;

// No ABSPATH guard: pure definitions with no include-time side effects.

/**
 * Typed reads of one object's TAW fields through a store — the term (and
 * user) counterpart of Metabox's static post helpers (`get_bool()`,
 * `get_repeater()`, …), with the same return shapes.
 *
 * ```php
 * $genre = Metabox::term($term->term_id);
 * $genre->get('genre_tagline');
 * $genre->repeater('genre_awards');
 * ```
 *
 * For typed, escaped values (images, rows, posts), prefer `TAW\Taw::term()`
 * and `Taw::user()` (ADR-0009); this reader stays as it is.
 */
final class FieldReader
{
    public function __construct(
        private readonly MetaStore $store,
        private readonly int $objectId,
        private readonly string $prefix = '_taw_'
    ) {
    }

    /** The raw stored value, '' when not set. */
    public function get(string $fieldId): mixed
    {
        return $this->objectId > 0 ? $this->store->get($this->objectId, $this->prefix . $fieldId) : '';
    }

    /** A checkbox: true when the stored value is '1'. */
    public function bool(string $fieldId): bool
    {
        return (string) $this->get($fieldId) === '1';
    }

    /** An image field's URL, '' when none is set. */
    public function imageUrl(string $fieldId, string $size = 'full'): string
    {
        $attachmentId = absint($this->get($fieldId));

        return $attachmentId ? (wp_get_attachment_image_url($attachmentId, $size) ?: '') : '';
    }

    public function color(string $fieldId, string $fallback = ''): string
    {
        $color = (string) $this->get($fieldId);

        return $color !== '' ? $color : $fallback;
    }

    /**
     * A post_select field as post IDs (single or multiple mode).
     *
     * @return int[]
     */
    public function posts(string $fieldId): array
    {
        $raw = $this->get($fieldId);
        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            return array_filter(array_map('absint', $decoded));
        }

        $id = absint($raw);

        return $id ? [$id] : [];
    }

    /**
     * A repeater's rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function repeater(string $fieldId): array
    {
        return $this->jsonList($fieldId);
    }

    /**
     * A gradient_text field's `{text, highlighted}` segments.
     *
     * @return array<int, array<string, mixed>>
     */
    public function gradientText(string $fieldId): array
    {
        return $this->jsonList($fieldId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function jsonList(string $fieldId): array
    {
        $raw = $this->get($fieldId);
        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}

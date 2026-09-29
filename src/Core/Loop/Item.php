<?php

declare(strict_types=1);

namespace TAW\Core\Loop;

use TAW\Core\Fields\Row;

// No ABSPATH guard: a plain value object.

/**
 * One item of a TAW Loop (ADR-0014): a repeater row, a post, a term or an
 * image, with its position. Passed to the item's blocks as the
 * `taw/loopItem` block context; `@row.*` and `@loop.*` read it.
 */
final class Item
{
    public const ROW = 'row';

    public const POST = 'post';

    public const TERM = 'term';

    public const IMAGE = 'image';

    /**
     * @param Row|\WP_Term|int|null $data A row, a term, an attachment ID, or null for a post.
     */
    public function __construct(
        public readonly string $kind,
        public readonly Row|\WP_Term|int|null $data,
        public readonly int $postId = 0,
        public int $index = 0,
        public int $count = 0,
    ) {
    }

    public static function row(Row $row, int $postId): self
    {
        return new self(self::ROW, $row, $postId);
    }

    public static function post(int $postId): self
    {
        return new self(self::POST, null, $postId);
    }

    public static function term(\WP_Term $term, int $postId): self
    {
        return new self(self::TERM, $term, $postId);
    }

    public static function image(int $attachmentId, int $postId): self
    {
        return new self(self::IMAGE, $attachmentId, $postId);
    }

    /** `@loop.*`: index (1-based), count, first, last, even, odd. Booleans are "1" or "". */
    public function loop(string $name): ?string
    {
        return match ($name) {
            'index' => (string) $this->index,
            'count' => (string) $this->count,
            'first' => $this->index === 1 ? '1' : '',
            'last'  => $this->index === $this->count ? '1' : '',
            'even'  => $this->index % 2 === 0 ? '1' : '',
            'odd'   => $this->index % 2 === 1 ? '1' : '',
            default => null,
        };
    }
}

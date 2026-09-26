<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What a `taw/field` binding asks for: the block's `metadata.bindings.<attr>.args`.
 *
 *   {"field": "book_cover", "from": "post", "size": "large"}
 *   {"field": "social", "sub": "instagram", "from": "option"}
 *
 * `field` is a bare id, qualified id or meta key (as `Taw::post()->field()`),
 * `from` is post (default), option, term or user, `sub` a group's sub-field
 * and `size` an image size.
 *
 * Inline dynamic tags (ADR-0011) use the same args, or a property instead of
 * a field, and may carry a date `format`:
 *
 *   {"tag": "post.date", "format": "F j, Y"}
 */
final class Reference
{
    public const FROM = ['post', 'option', 'term', 'user'];

    private function __construct(
        public readonly string $field,
        public readonly string $from,
        public readonly ?string $sub,
        public readonly string $size,
        public readonly ?string $tag = null,
        public readonly ?string $format = null,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function fromArgs(array $args): ?self
    {
        $format = isset($args['format']) && is_string($args['format']) && trim($args['format']) !== '' ? $args['format'] : null;

        if (isset($args['tag'])) {
            return is_string($args['tag']) && TagResolver::knows($args['tag'])
                ? new self('', 'post', null, 'full', $args['tag'], $format)
                : null;
        }

        $field = $args['field'] ?? null;
        $from  = $args['from'] ?? 'post';
        if (!is_string($field) || trim($field) === '' || !is_string($from) || !in_array($from, self::FROM, true)) {
            return null;
        }

        $sub  = isset($args['sub']) && is_string($args['sub']) && $args['sub'] !== '' ? $args['sub'] : null;
        $size = isset($args['size']) && is_string($args['size']) && $args['size'] !== '' ? $args['size'] : 'full';

        return new self(trim($field), $from, $sub, $size, null, $format);
    }
}

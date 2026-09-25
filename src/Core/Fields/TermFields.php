<?php

declare(strict_types=1);

namespace TAW\Core\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A term's TAW fields (term fieldsets, `"on": ["term:genre"]`):
 * `Taw::term($id)->field('genre_tagline')`.
 */
final class TermFields extends MetaFields
{
    public function __construct(private readonly ?\WP_Term $term)
    {
    }

    public function id(): int
    {
        return $this->term !== null ? (int) $this->term->term_id : 0;
    }

    public function term(): ?\WP_Term
    {
        return $this->term;
    }

    protected function objectType(): string
    {
        return 'term';
    }

    protected function subtype(): string
    {
        return $this->term !== null ? (string) $this->term->taxonomy : '';
    }

    protected function read(string $key): mixed
    {
        return get_term_meta($this->id(), $key, true);
    }
}

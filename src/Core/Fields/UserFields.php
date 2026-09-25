<?php

declare(strict_types=1);

namespace TAW\Core\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A user's TAW fields (user fieldsets, `"on": ["user"]`):
 * `Taw::user($id)->field('author_links')->rows()`.
 */
final class UserFields extends MetaFields
{
    public function __construct(private readonly ?\WP_User $user)
    {
    }

    public function id(): int
    {
        return $this->user !== null ? (int) $this->user->ID : 0;
    }

    public function user(): ?\WP_User
    {
        return $this->user;
    }

    protected function objectType(): string
    {
        return 'user';
    }

    protected function subtype(): string
    {
        return '';
    }

    protected function read(string $key): mixed
    {
        return get_user_meta($this->id(), $key, true);
    }
}

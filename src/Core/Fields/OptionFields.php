<?php

declare(strict_types=1);

namespace TAW\Core\Fields;

use TAW\Core\OptionsPage\OptionsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Options-page fields: every page's (`Taw::option('company_phone')`), or one
 * page's (`Taw::options('site')->field('company_phone')`). A field is found
 * by its option name (`_taw_company_phone`) or its id; group sub-fields are
 * their own options (`{prefix}{group}_{sub}`).
 */
final class OptionFields extends Fields
{
    public function __construct(private readonly ?string $page = null)
    {
    }

    /** The options page these fields are limited to, or null for every page. */
    public function page(): ?string
    {
        return $this->page;
    }

    public function exists(): bool
    {
        return true;
    }

    protected function lookup(string $ref): ?array
    {
        return $this->find(OptionsPage::getFieldRegistry(), $ref);
    }

    protected function lookupGroup(string $ref): ?array
    {
        return $this->find(OptionsPage::getGroupRegistry(), $ref);
    }

    protected function subFieldRef(array $group, string $sub): string
    {
        return (string) ($group['prefix'] ?? '_taw_') . $group['id'] . '_' . $sub;
    }

    protected function keyOf(array $config): string
    {
        return (string) ($config['prefix'] ?? '_taw_') . (string) ($config['id'] ?? '');
    }

    protected function read(string $key): mixed
    {
        return get_option($key, '');
    }

    /**
     * @param array<string, array<string, mixed>> $registry option name → config
     * @return array<string, mixed>|null
     */
    private function find(array $registry, string $ref): ?array
    {
        if ($this->page !== null) {
            $registry = array_filter($registry, fn (array $config): bool => ($config['option_page'] ?? null) === $this->page);
        }

        if (isset($registry[$ref])) {
            return $registry[$ref];
        }

        return self::tawFirst(array_values(array_filter($registry, static fn (array $config): bool => ($config['id'] ?? null) === $ref)))[0] ?? null;
    }
}

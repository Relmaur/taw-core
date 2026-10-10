<?php

declare(strict_types=1);

namespace TAW\Update;

/**
 * What a migration did: the theme files it wrote, and what's left for a
 * person (each a complete instruction, not a hint).
 */
final class MigrationResult
{
    /**
     * @param list<string> $changed theme-relative paths written
     * @param list<string> $manual steps a person must take (empty = fully done)
     */
    public function __construct(public readonly array $changed = [], public readonly array $manual = [])
    {
    }

    /** @return array{changed: list<string>, manual: list<string>} */
    public function toArray(): array
    {
        return ['changed' => $this->changed, 'manual' => $this->manual];
    }
}

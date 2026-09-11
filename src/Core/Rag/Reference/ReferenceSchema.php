<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Reference;

/**
 * Value object describing one reference-DB table shape. Deliberately pure
 * PHP with no ABSPATH guard — {@see ReferenceImporter} and
 * `content:import-reference` need to run against an arbitrary SQLite file
 * via plain PDO, before WordPress (or even a theme) is ever involved.
 *
 * Table/column names here are fixed constants, never user input, so they're
 * safe to interpolate directly into SQL in {@see SchemaManager} and
 * {@see ReferenceImporter}.
 */
final class ReferenceSchema
{
    /**
     * @param list<string> $columns    Every stored column, natural-key columns included.
     * @param list<string> $naturalKey Subset of $columns that uniquely identifies a row.
     */
    private function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly array $columns,
        public readonly array $naturalKey,
    ) {
    }

    public static function bible(): self
    {
        return new self('bible', 'verses', ['book', 'chapter', 'verse', 'text'], ['book', 'chapter', 'verse']);
    }

    public static function catechism(): self
    {
        return new self('catechism', 'entries', ['part', 'question', 'topic', 'text'], ['part', 'question']);
    }

    public static function forName(string $name): self
    {
        return match ($name) {
            'bible' => self::bible(),
            'catechism' => self::catechism(),
            default => throw new \InvalidArgumentException("Unknown reference schema '{$name}' — use 'bible' or 'catechism'."),
        };
    }
}

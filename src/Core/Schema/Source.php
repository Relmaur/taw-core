<?php

declare(strict_types=1);

namespace TAW\Core\Schema;

// No ABSPATH guard: pure value object, loadable by bin/taw before WordPress
// boots (schema:validate). See ADR-0004 § Decision 8.

/**
 * Where a schema definition came from, and how much it outranks others.
 *
 * The same entity (e.g. post_type:book) can be defined in more than one
 * place — PHP code, a child theme's JSON, a parent theme's JSON, wp-content
 * JSON. When that happens the higher rank wins (ADR-0004 § Decision 6).
 * PHP ranks highest because code is where conditional logic lives, so it
 * should always get the last word over static configuration files.
 */
final class Source
{
    public const RANK_PHP          = 100;
    public const RANK_CHILD_THEME  = 40;
    public const RANK_PARENT_THEME = 30;
    public const RANK_WP_CONTENT   = 20;

    private function __construct(
        public readonly string $type,
        public readonly int $rank,
        public readonly ?string $path = null,
    ) {
    }

    /**
     * A definition registered from PHP (the taw_schema_register action).
     */
    public static function php(): self
    {
        return new self('php', self::RANK_PHP);
    }

    /**
     * A definition loaded from a JSON file, ranked by the directory it was
     * discovered in (one of the RANK_* constants).
     */
    public static function json(string $path, int $rank): self
    {
        return new self('json', $rank, $path);
    }

    /**
     * Human-readable origin for notices: "php" or "json:/path/to/file.json".
     */
    public function describe(): string
    {
        return $this->path === null ? $this->type : $this->type . ':' . $this->path;
    }
}

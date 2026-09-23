<?php

declare(strict_types=1);

namespace TAW\Core\Schema\Definition;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * Base class for everything the schema registry holds: post types,
 * taxonomies, fieldsets and options pages.
 *
 * A definition is inert data. It never calls WordPress itself; the Compiler
 * turns it into register_post_type() / register_taxonomy() / new Metabox() /
 * new OptionsPage() at the right init priority (ADR-0004 § Decision 3). That
 * split is what lets the same definition come from PHP or JSON, be validated
 * without WordPress (bin/taw schema:validate), and be compared across sources.
 */
abstract class Definition
{
    /** Set when this definition deliberately replaces a lower-ranked one. */
    private bool $override = false;

    final public function __construct(private readonly string $key)
    {
        $problem = static::keyProblem($key);
        if ($problem !== null) {
            throw new \InvalidArgumentException(sprintf('Invalid %s key "%s": %s', static::KIND, $key, $problem));
        }
    }

    /** The registry kind, e.g. "post_type". Also the JSON "kind" value. */
    public const KIND = '';

    public function kind(): string
    {
        return static::KIND;
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * Registry key, unique across kinds: "post_type:book", "fieldset:book_details".
     * A post type and a fieldset may share a bare key without clashing.
     */
    public function qualifiedKey(): string
    {
        return static::KIND . ':' . $this->key;
    }

    /**
     * Declare that this definition intentionally replaces a lower-ranked
     * definition of the same entity (e.g. PHP overriding a theme JSON file).
     * Without it, a replacement still happens but triggers a debug notice,
     * so accidental duplicates don't go unnoticed.
     */
    public function override(bool $override = true): static
    {
        $this->override = $override;

        return $this;
    }

    public function isOverride(): bool
    {
        return $this->override;
    }

    /**
     * Problems that stop this definition from being registered (e.g. a
     * fieldset with no location). Empty when it's valid. Checked by the
     * Registry on add(), so a broken definition is reported and skipped
     * instead of half-registering.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return [];
    }

    /**
     * Canonical array form — what the Compiler feeds to WordPress, and what
     * the rewrite fingerprint hashes.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * Why $key can't be used for this kind, or null when it's fine.
     */
    protected static function keyProblem(string $key): ?string
    {
        return preg_match('/^[a-z0-9_-]+$/', $key) === 1
            ? null
            : 'use lowercase letters, numbers, underscores and dashes only';
    }
}

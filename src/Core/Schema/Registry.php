<?php

declare(strict_types=1);

namespace TAW\Core\Schema;

use TAW\Core\Schema\Definition\Definition;
use TAW\Core\Schema\Definition\EditingPolicy;
use TAW\Core\Schema\Definition\Fieldset;
use TAW\Core\Schema\Definition\OptionsPage;
use TAW\Core\Schema\Definition\PostType;
use TAW\Core\Schema\Definition\Taxonomy;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * The single place schema definitions are collected (ADR-0004).
 *
 * LIFECYCLE (one request):
 *   1. collecting — init:1. PHP definitions arrive through the
 *      taw_schema_register action (JSON files from Step 4 of the plan);
 *   2. frozen     — init:5. The Compiler freezes the registry before it
 *      registers anything with WordPress, so what gets registered is a
 *      stable, complete set. A late add() is refused, not silently ignored.
 *
 * PRECEDENCE: the same entity (same kind + key) may be defined by several
 * sources. The higher Source::rank wins; on a tie the later one wins. When a
 * definition replaces another without declaring ->override(), a
 * _doing_it_wrong() notice names both sources — replacements should be
 * deliberate, and a duplicate key is more often a copy-paste accident.
 */
final class Registry
{
    private static ?self $instance = null;

    /** @var array<string, array{definition: Definition, source: Source}> keyed by qualified key */
    private array $entries = [];

    private bool $frozen = false;

    /**
     * The request-wide registry. One per request, because WordPress's own
     * post type / taxonomy / meta registries are request-wide too.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @internal For tests only — start a request from scratch.
     */
    public static function resetForTests(): void
    {
        self::$instance = null;
    }

    /**
     * Add a definition. Returns false (with a notice) if it was refused:
     * the registry is frozen, the definition is invalid, or a
     * higher-ranked definition of the same entity already exists.
     */
    public function add(Definition $definition, ?Source $source = null): bool
    {
        $source ??= Source::php();
        $qualifiedKey = $definition->qualifiedKey();

        if ($this->frozen) {
            self::warn(__METHOD__, sprintf(
                'Schema definition "%s" was added after the schema registry froze (init priority 5). '
                . 'Add definitions from the taw_schema_register action instead.',
                $qualifiedKey
            ));

            return false;
        }

        $problems = $definition->problems();
        if ($problems !== []) {
            foreach ($problems as $problem) {
                self::warn(__METHOD__, $problem . ' (' . $source->describe() . ')');
            }

            return false;
        }

        $existing = $this->entries[$qualifiedKey] ?? null;
        if ($existing !== null) {
            $incomingWins = $source->rank >= $existing['source']->rank;
            $winner = $incomingWins ? $definition : $existing['definition'];

            if (!$winner->isOverride()) {
                self::warn(__METHOD__, sprintf(
                    '"%s" is defined twice (%s and %s); using %s. Mark the winning definition with '
                    . 'override() (PHP) or "override": true (JSON) if this is intentional.',
                    $qualifiedKey,
                    $existing['source']->describe(),
                    $source->describe(),
                    ($incomingWins ? $source : $existing['source'])->describe()
                ));
            }

            if (!$incomingWins) {
                return false;
            }
        }

        $this->entries[$qualifiedKey] = ['definition' => $definition, 'source' => $source];

        return true;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function get(string $qualifiedKey): ?Definition
    {
        return $this->entries[$qualifiedKey]['definition'] ?? null;
    }

    public function sourceOf(string $qualifiedKey): ?Source
    {
        return $this->entries[$qualifiedKey]['source'] ?? null;
    }

    /** @return list<PostType> */
    public function postTypes(): array
    {
        return $this->ofKind(PostType::class);
    }

    /** @return list<Taxonomy> */
    public function taxonomies(): array
    {
        return $this->ofKind(Taxonomy::class);
    }

    /** @return list<Fieldset> */
    public function fieldsets(): array
    {
        return $this->ofKind(Fieldset::class);
    }

    /** @return list<OptionsPage> */
    public function optionsPages(): array
    {
        return $this->ofKind(OptionsPage::class);
    }

    /**
     * The site's editing policy, or null when none is defined. There is at
     * most one: its key is always "site", so a second definition replaces
     * the first through the usual precedence rules.
     */
    public function editing(): ?EditingPolicy
    {
        return $this->ofKind(EditingPolicy::class)[0] ?? null;
    }

    /**
     * Report a developer mistake the WordPress way: _doing_it_wrong(), which
     * only surfaces when WP_DEBUG is on. Silent outside WordPress (e.g.
     * under bin/taw), where callers report problems themselves.
     *
     * @internal Shared by the Schema classes.
     */
    public static function warn(string $where, string $message): void
    {
        if (function_exists('_doing_it_wrong')) {
            _doing_it_wrong($where, $message, '1.43.0');
        }
    }

    /**
     * @template T of Definition
     * @param class-string<T> $class
     * @return list<T>
     */
    private function ofKind(string $class): array
    {
        $matches = [];
        foreach ($this->entries as $entry) {
            if ($entry['definition'] instanceof $class) {
                $matches[] = $entry['definition'];
            }
        }

        return $matches;
    }
}

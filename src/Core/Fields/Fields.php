<?php

declare(strict_types=1);

namespace TAW\Core\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The TAW fields of one object (a post, term or user, or the site's
 * options), read as typed {@see Value}s (ADR-0009). `field()` takes a bare
 * id, a qualified id (`fieldset.field`) or a meta key / option name.
 */
abstract class Fields
{
    /** @var array<string, Value> */
    private array $values = [];

    /** Whether there's anything to read (a missing object reads every field as empty). */
    abstract public function exists(): bool;

    /**
     * The config of the field `$ref` means here, or null.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function lookup(string $ref): ?array;

    /**
     * The group field `$ref` means here, or null. Groups own no stored value;
     * their sub-fields are stored as `{prefix}{group}_{sub}`.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function lookupGroup(string $ref): ?array;

    /**
     * The reference that reaches a group's sub-field.
     *
     * @param array<string, mixed> $group
     */
    abstract protected function subFieldRef(array $group, string $sub): string;

    /**
     * Where a field config stores its value (meta key or option name).
     *
     * @param array<string, mixed> $config
     */
    abstract protected function keyOf(array $config): string;

    abstract protected function read(string $key): mixed;

    public function field(string $ref): Value
    {
        return $this->values[$ref] ??= $this->resolve($ref);
    }

    private function resolve(string $ref): Value
    {
        if (!$this->exists()) {
            return Value::none($ref);
        }

        $config = $this->lookup($ref);
        if ($config !== null) {
            return new Value($config, $this->read($this->keyOf($config)), null, $ref);
        }

        $group = $this->lookupGroup($ref);
        if ($group !== null) {
            return new Value($group, '', fn (string $sub): Value => $this->field($this->subFieldRef($group, $sub)), $ref);
        }

        // Not registered here: read `_taw_<id>` (or the key given), untyped.
        $key = str_starts_with($ref, '_') ? $ref : '_taw_' . (str_contains($ref, '.') ? substr($ref, (int) strrpos($ref, '.') + 1) : $ref);

        return new Value(null, $this->read($key), null, $ref);
    }

    /**
     * The `_taw_` field first, when several prefixes share an id (ADR-0008).
     *
     * @param list<array<string, mixed>> $configs
     * @return list<array<string, mixed>>
     */
    protected static function tawFirst(array $configs): array
    {
        usort($configs, static fn (array $a, array $b): int => (($b['prefix'] ?? '') === '_taw_') <=> (($a['prefix'] ?? '') === '_taw_'));

        return $configs;
    }
}

<?php

declare(strict_types=1);

namespace TAW\Core\Fields;

use TAW\Core\Metabox\Metabox;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The TAW fields of one object (a post; terms, users and options in later
 * steps), read as typed {@see Value}s (ADR-0009). Fields resolve through the
 * qualified registry (ADR-0008), so `field()` takes a bare id, a qualified
 * id (`fieldset.field`) or a meta key.
 */
abstract class Fields
{
    /** @var array<string, Value> */
    private array $values = [];

    /** 'post', 'term' or 'user'. */
    abstract protected function objectType(): string;

    /** The post type or taxonomy ('' for users). */
    abstract protected function subtype(): string;

    abstract public function id(): int;

    abstract protected function read(string $metaKey): mixed;

    /** Whether the object exists (a missing one reads every field as empty). */
    public function exists(): bool
    {
        return $this->id() > 0;
    }

    public function field(string $ref): Value
    {
        return $this->values[$ref] ??= $this->resolve($ref);
    }

    private function resolve(string $ref): Value
    {
        if (!$this->exists()) {
            return Value::none($ref);
        }

        $config = Metabox::fieldFor($this->objectType(), $this->subtype(), $ref);
        if ($config !== null) {
            return new Value($config, $this->read(Metabox::metaKeyOf($config)), null, $ref);
        }

        $group = $this->group($ref);
        if ($group !== null) {
            $box = (string) ($group['metabox_id'] ?? '');
            $key = (string) $group['field_key'];

            return new Value($group, '', fn (string $sub): Value => $this->field($box . '.' . $key . '_' . $sub), $ref);
        }

        // Not registered for this object: read `_taw_<id>` (or the meta key given), untyped.
        $metaKey = str_starts_with($ref, '_') ? $ref : '_taw_' . (str_contains($ref, '.') ? substr($ref, (int) strrpos($ref, '.') + 1) : $ref);

        return new Value(null, $this->read($metaKey), null, $ref);
    }

    /**
     * A group field on this object: group parents own no meta, so they're
     * found through their sub-fields.
     *
     * @return array<string, mixed>|null
     */
    private function group(string $ref): ?array
    {
        $registry = Metabox::getQualifiedRegistry();
        $fields = Metabox::fieldsFor($this->objectType(), $this->subtype());
        $candidates = [];

        foreach ($registry as $config) {
            if (($config['type'] ?? '') !== 'group' || ($config['qualified_id'] !== $ref && $config['field_key'] !== $ref)) {
                continue;
            }
            foreach ($fields as $sub) {
                if (($sub['parent_group'] ?? null) === $config['field_key'] && ($sub['metabox_id'] ?? null) === ($config['metabox_id'] ?? null)) {
                    $candidates[] = $config;
                    break;
                }
            }
        }
        // As for other fields, the `_taw_` one first.
        usort($candidates, static fn (array $a, array $b): int => (($b['prefix'] ?? '') === '_taw_') <=> (($a['prefix'] ?? '') === '_taw_'));

        return $candidates[0] ?? null;
    }
}

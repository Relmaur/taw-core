<?php

declare(strict_types=1);

namespace TAW\Core\Fields;

use TAW\Core\Metabox\Metabox;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fields stored as object meta (posts, terms, users), resolved through the
 * metabox engine's qualified registry (ADR-0008).
 */
abstract class MetaFields extends Fields
{
    /** 'post', 'term' or 'user'. */
    abstract protected function objectType(): string;

    /** The post type or taxonomy ('' for users). */
    abstract protected function subtype(): string;

    abstract public function id(): int;

    public function exists(): bool
    {
        return $this->id() > 0;
    }

    protected function lookup(string $ref): ?array
    {
        return Metabox::fieldFor($this->objectType(), $this->subtype(), $ref);
    }

    protected function lookupGroup(string $ref): ?array
    {
        $fields = Metabox::fieldsFor($this->objectType(), $this->subtype());
        $candidates = [];

        foreach (Metabox::getQualifiedRegistry() as $config) {
            if (($config['type'] ?? '') !== 'group' || ($config['qualified_id'] !== $ref && $config['field_key'] !== $ref)) {
                continue;
            }
            // Group parents own no meta, so fieldsFor() leaves them out: a
            // group applies here when one of its sub-fields does.
            foreach ($fields as $sub) {
                if (($sub['parent_group'] ?? null) === $config['field_key'] && ($sub['metabox_id'] ?? null) === ($config['metabox_id'] ?? null)) {
                    $candidates[] = $config;
                    break;
                }
            }
        }

        return self::tawFirst($candidates)[0] ?? null;
    }

    protected function subFieldRef(array $group, string $sub): string
    {
        return ($group['metabox_id'] ?? '') . '.' . $group['field_key'] . '_' . $sub;
    }

    protected function keyOf(array $config): string
    {
        return Metabox::metaKeyOf($config);
    }
}

<?php

declare(strict_types=1);

namespace TAW\Core\Content;

use TAW\Core\Block\BlockRegistry;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A compact description of the field/block registry at the moment a
 * snapshot was taken — recorded in the snapshot's `meta.registry_fingerprint`
 * so the importer can warn when the target site's registry has drifted
 * (a block removed, a field's type changed) rather than silently writing
 * a value the destination can no longer render.
 *
 * Shape: `{ blocks: string[], fields: { <field_id>: <type> }, options: { <option_name>: <type> } }`.
 */
final class RegistryFingerprint
{
    /**
     * Build the fingerprint for the live site (WordPress must be booted).
     *
     * @return array{blocks: list<string>, fields: array<string, string>, options: array<string, string>}
     */
    public static function current(): array
    {
        $blocks = array_map('strval', array_keys(BlockRegistry::getAll()));
        sort($blocks);

        $fields = [];
        foreach (Metabox::getFieldRegistry() as $fieldId => $config) {
            $fields[(string) $fieldId] = (string) ($config['type'] ?? 'text');
        }
        ksort($fields);

        $options = [];
        foreach (OptionsPage::getFieldRegistry() as $optionName => $config) {
            $options[(string) $optionName] = (string) ($config['type'] ?? 'text');
        }
        ksort($options);

        return ['blocks' => $blocks, 'fields' => $fields, 'options' => $options];
    }

    /**
     * Compare a snapshot's recorded fingerprint against another (usually the
     * live target site's `current()`), returning human-readable drift notes.
     *
     * @param array<string, mixed> $recorded The `meta.registry_fingerprint` from the snapshot.
     * @param array<string, mixed> $target   Usually {@see self::current()}.
     * @return list<string> One line per drift; empty when the registries match.
     */
    public static function drift(array $recorded, array $target): array
    {
        $notes = [];

        $recordedBlocks = self::stringList($recorded['blocks'] ?? []);
        $targetBlocks   = self::stringList($target['blocks'] ?? []);
        foreach (array_diff($recordedBlocks, $targetBlocks) as $missing) {
            $notes[] = "Block '{$missing}' is in the snapshot but not registered on the target site.";
        }

        foreach (['fields', 'options'] as $bucket) {
            $recordedMap = self::stringMap($recorded[$bucket] ?? []);
            $targetMap   = self::stringMap($target[$bucket] ?? []);

            foreach ($recordedMap as $id => $type) {
                if (!array_key_exists($id, $targetMap)) {
                    $notes[] = ucfirst($bucket) . " entry '{$id}' ({$type}) is in the snapshot but not registered on the target site.";
                    continue;
                }
                if ($targetMap[$id] !== $type) {
                    $notes[] = ucfirst($bucket) . " entry '{$id}' changed type: snapshot '{$type}' → target '{$targetMap[$id]}'.";
                }
            }
        }

        return $notes;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    /**
     * @param mixed $value
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = (string) $v;
        }
        return $out;
    }
}

<?php

declare(strict_types=1);

namespace TAW\Core\Schema;

use TAW\Core\Metabox\Metabox;
use TAW\Core\Schema\Definition\Fieldset;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * Detects schema fields whose id clashes with another field (ADR-0004 § 7).
 *
 * WHY THIS MATTERS: since ADR-0008 every field is also registered under its
 * qualified id ("{fieldset}.{field}"), and REST meta, content export/import,
 * the data panel, the visual editor and bin/taw fields:* resolve fields per
 * post type through it, so each fieldset keeps its own config. What stays
 * ambiguous is the bare-id API: `Metabox::get_field_config($id)` and the
 * bare registry still hold one entry per id (the later one), and on a post
 * type with both fields, `Metabox::get($postId, $id)` only reads the `_taw_`
 * prefix. A developer should still hear about it.
 *
 * HOW: collisions can happen in either direction —
 *   - before: when a fieldset compiles (init:8), an entry with its field id
 *     may already exist (a Metabox built earlier, or another fieldset);
 *   - after:  a MetaBlock metabox compiled later (init:10) may overwrite a
 *     schema field's entry.
 * So ids are checked right before each fieldset compiles, and again at
 * init:15 once every metabox exists. Collisions between two legacy
 * (non-schema) metaboxes are out of scope here — reporting those at runtime
 * would change output for existing taw-theme sites.
 */
final class CollisionReport
{
    /** @var array<string, string> field id → fieldset key that claimed it */
    private static array $claimed = [];

    /** @var list<string> */
    private static array $collisions = [];

    /**
     * Call right before compiling $fieldset into a Metabox.
     */
    public static function checkBeforeCompile(Fieldset $fieldset): void
    {
        foreach ($fieldset->registryFieldIds() as $fieldId) {
            if (isset(self::$claimed[$fieldId]) && self::$claimed[$fieldId] !== $fieldset->key()) {
                self::$collisions[] = sprintf(
                    'Field "%s" is defined by both schema fieldsets "%s" and "%s"; each keeps its own config, but get_field_config() returns only "%s"\'s.',
                    $fieldId,
                    self::$claimed[$fieldId],
                    $fieldset->key(),
                    $fieldset->key()
                );
            } else {
                $existing = Metabox::get_field_config($fieldId);
                if ($existing !== null && ($existing['metabox_id'] ?? null) !== $fieldset->key()) {
                    self::$collisions[] = sprintf(
                        'Schema fieldset "%s" field "%s" shares its id with metabox "%s"; each keeps its own config, but get_field_config() returns only the schema field.',
                        $fieldset->key(),
                        $fieldId,
                        (string) ($existing['metabox_id'] ?? '?')
                    );
                }
            }

            self::$claimed[$fieldId] = $fieldset->key();
        }
    }

    /**
     * Call once every metabox has been constructed (init:15): catches schema
     * fields overwritten by a metabox registered after them.
     */
    public static function checkAfterAllMetaboxes(): void
    {
        foreach (self::$claimed as $fieldId => $fieldsetKey) {
            $current = Metabox::get_field_config($fieldId);
            $owner = $current['metabox_id'] ?? null;

            if ($current !== null && $owner !== $fieldsetKey) {
                self::$collisions[] = sprintf(
                    'Metabox "%s" (registered after the schema) shares an id with schema fieldset "%s" field "%s"; each keeps its own config, but get_field_config() returns only the metabox\'s.',
                    (string) $owner,
                    $fieldsetKey,
                    $fieldId
                );
            }
        }
    }

    /**
     * Report every collision found: a _doing_it_wrong() notice each, plus a
     * single admin notice for administrators when WP_DEBUG is on (notices in
     * the debug log are easy to miss).
     */
    public static function report(): void
    {
        if (self::$collisions === []) {
            return;
        }

        foreach (self::$collisions as $collision) {
            Registry::warn(__METHOD__, $collision . ' Rename one of the fields, or use qualified ids (fieldset.field).');
        }

        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('add_action')) {
            $collisions = self::$collisions;
            add_action('admin_notices', static function () use ($collisions): void {
                if (!current_user_can('manage_options')) {
                    return;
                }

                echo '<div class="notice notice-warning"><p><strong>TAW schema: field id collisions</strong></p><ul>';
                foreach ($collisions as $collision) {
                    echo '<li>' . esc_html($collision) . '</li>';
                }
                echo '</ul></div>';
            });
        }
    }

    /**
     * @return list<string>
     */
    public static function collisions(): array
    {
        return self::$collisions;
    }

    /**
     * @internal For tests only.
     */
    public static function resetForTests(): void
    {
        self::$claimed = [];
        self::$collisions = [];
    }
}

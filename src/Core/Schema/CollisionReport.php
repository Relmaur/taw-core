<?php

declare(strict_types=1);

namespace TAW\Core\Schema;

use TAW\Core\Metabox\Metabox;
use TAW\Core\Schema\Definition\Fieldset;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * Detects schema fields whose id clashes with another field (ADR-0004 § 7).
 *
 * WHY THIS MATTERS: Metabox's field registry is keyed by the bare field id,
 * so two fields named "subtitle" in different metaboxes share one registry
 * entry — the later one overwrites the earlier. The meta values themselves
 * are separate per post, but everything that reads the registry (REST meta
 * registration, content export, bin/taw fields:*) sees only one of the two
 * configs. That's a latent bug a developer should hear about, but fixing
 * the registry changes behavior for existing sites, so Phase 1 only
 * DETECTS; Phase 2 fixes it.
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
                    'Field "%s" is defined by both schema fieldsets "%s" and "%s"; the registry keeps only "%s".',
                    $fieldId,
                    self::$claimed[$fieldId],
                    $fieldset->key(),
                    $fieldset->key()
                );
            } else {
                $existing = Metabox::get_field_config($fieldId);
                if ($existing !== null && ($existing['metabox_id'] ?? null) !== $fieldset->key()) {
                    self::$collisions[] = sprintf(
                        'Schema fieldset "%s" field "%s" replaces the registry entry of metabox "%s".',
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
                    'Metabox "%s" (registered after the schema) replaces the registry entry of schema fieldset "%s" field "%s".',
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
            Registry::warn(__METHOD__, $collision . ' Rename one of the fields.');
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

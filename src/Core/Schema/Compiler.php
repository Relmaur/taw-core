<?php

declare(strict_types=1);

namespace TAW\Core\Schema;

use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage as OptionsPageEngine;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * Turns the schema registry into real WordPress registrations, each at the
 * init priority it needs (ADR-0004 § Decision 3):
 *
 *   init:1   collect   fire taw_schema_register so PHP code can add definitions
 *   init:5   freeze    then register_post_type()
 *   init:6             register_taxonomy() — post types now exist to attach to
 *   init:8   compile   fieldsets → new Metabox, options pages → new OptionsPage
 *   (init:10           existing MetaBlock metaboxes — unchanged)
 *   init:15  report    field id collisions, now that every metabox exists
 *   (init:20           existing FieldMetaRegistrar: REST meta — sees our post
 *                      types because they were registered at init:5)
 *   init:99  rewrite   flush rewrite rules once when post types/taxonomies change
 *
 * With no definitions, every step is a cheap no-op — so Boot::data() can
 * always wire this, including for classic taw-theme sites.
 */
final class Compiler
{
    /** Option holding the fingerprint of the last post types/taxonomies flushed for. */
    public const REWRITE_OPTION = 'taw_schema_rewrite_hash';

    public static function register(): void
    {
        add_action('init', [self::class, 'collect'], 1);
        add_action('init', [self::class, 'registerPostTypes'], 5);
        add_action('init', [self::class, 'registerTaxonomies'], 6);
        add_action('init', [self::class, 'compileFields'], 8);
        add_action('init', [self::class, 'reportCollisions'], 15);
        add_action('init', [self::class, 'maybeFlushRewriteRules'], 99);
    }

    /** @internal init:1 */
    public static function collect(): void
    {
        do_action('taw_schema_register', Registry::instance());
    }

    /** @internal init:5 */
    public static function registerPostTypes(): void
    {
        $registry = Registry::instance();
        $registry->freeze();

        foreach ($registry->postTypes() as $postType) {
            register_post_type($postType->key(), $postType->toArray());
        }
    }

    /** @internal init:6 */
    public static function registerTaxonomies(): void
    {
        foreach (Registry::instance()->taxonomies() as $taxonomy) {
            $definition = $taxonomy->toArray();
            register_taxonomy($taxonomy->key(), $definition['object_type'], $definition['args']);
        }
    }

    /** @internal init:8 */
    public static function compileFields(): void
    {
        $registry = Registry::instance();

        foreach ($registry->fieldsets() as $fieldset) {
            CollisionReport::checkBeforeCompile($fieldset);
            new Metabox($fieldset->toArray());
        }

        foreach ($registry->optionsPages() as $optionsPage) {
            new OptionsPageEngine($optionsPage->toArray());
        }
    }

    /** @internal init:15 */
    public static function reportCollisions(): void
    {
        CollisionReport::checkAfterAllMetaboxes();
        CollisionReport::report();
    }

    /**
     * init:99. New or changed post types / taxonomies need their rewrite
     * rules regenerated, or their URLs 404 until someone re-saves
     * Settings → Permalinks. Flushing is expensive, so it only happens when
     * the definitions actually changed, and only on admin requests (never on
     * the front end, where it would run for visitors).
     *
     * @internal
     */
    public static function maybeFlushRewriteRules(): void
    {
        if (!is_admin()) {
            return;
        }

        $registry = Registry::instance();
        $definitions = [];
        foreach ([...$registry->postTypes(), ...$registry->taxonomies()] as $definition) {
            $definitions[$definition->qualifiedKey()] = $definition->toArray();
        }
        ksort($definitions);

        $stored = get_option(self::REWRITE_OPTION, null);

        // A site that has never defined any: nothing to flush, and no reason
        // to write an option on every existing TAW site after upgrading.
        if ($definitions === [] && $stored === null) {
            return;
        }

        $fingerprint = md5((string) wp_json_encode($definitions));
        if ($stored === $fingerprint) {
            return;
        }

        flush_rewrite_rules(false);
        update_option(self::REWRITE_OPTION, $fingerprint);
    }
}

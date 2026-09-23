<?php

declare(strict_types=1);

namespace TAW\Core\Editing;

use TAW\Core\Schema\Registry;

// No ABSPATH guard: pure class definition (see Editing\Presets).

/**
 * Applies the site's editing policy (ADR-0005). Wired by Boot::editing()
 * only, never by Boot::data() or Theme::boot().
 *
 * The policy is resolved at init:7: the schema registry (which holds the
 * editing definition and post types' own rules) is collected at init:1 and
 * frozen at init:5, and core registers its block patterns at init:10, which
 * the features layer must get to first.
 */
final class Editing
{
    public const APPLY_PRIORITY = 7;

    private static ?Policy $policy = null;

    public static function register(): void
    {
        add_action('init', [self::class, 'apply'], self::APPLY_PRIORITY);
    }

    /**
     * Resolve the policy and hook each layer.
     */
    public static function apply(): void
    {
        $policy = self::policy();

        foreach ($policy->warnings as $warning) {
            Registry::warn(__METHOD__, $warning);
        }

        $bypass = Bypass::fromPolicy($policy);
        $bypass->register();

        (new ContentLayer($policy, $bypass))->register();
        (new FeaturesLayer($policy, $bypass))->register();
    }

    /**
     * The effective policy for this request. Resolved once; resolving before
     * the registry freezes (init:5) would miss definitions, so callers should
     * only ask from init:7 on.
     */
    public static function policy(): Policy
    {
        return self::$policy ??= Resolver::fromRegistry(Registry::instance());
    }

    /**
     * @internal For tests only.
     */
    public static function resetForTests(): void
    {
        self::$policy = null;
    }
}

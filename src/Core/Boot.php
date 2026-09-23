<?php

declare(strict_types=1);

namespace TAW\Core;

use TAW\Core\Content\ContentAdminScreen;
use TAW\Core\Rest\ContentEndpoint;
use TAW\Core\Rest\FieldMetaRegistrar;
use TAW\Core\Schema\Compiler;

// Deliberately NO `if (!defined('ABSPATH')) exit;` guard: this is a pure class
// definition, and `bin/taw` commands may autoload it before WordPress boots —
// the guard's `exit` would silently kill the command (see the Content\* classes).

/**
 * Boot — entry points for consuming taw/core, split by what a consumer needs.
 *
 * taw/core is two things in one Composer package (ADR-0003):
 *
 *   - a DATA LAYER: field storage, fields exposed over REST, content
 *     import/export — useful to any WordPress project, classic or block theme;
 *   - an optional CLASSIC-THEME TOOLKIT: PHP blocks from the theme's Blocks/
 *     folder, the Vite/Alpine pipeline, the visual editor, performance tweaks,
 *     SEO output.
 *
 * Boot::data() turns on only the first. A data-only consumer — a block or
 * hybrid theme (taw-gutenberg), or a site plugin — calls it after requiring
 * its Composer autoloader and gets no presentation side effects at all: no
 * dequeued block CSS, no Vite, no frontend output.
 *
 * Theme::boot() (the classic-theme entry point) calls Boot::data() itself, at
 * the exact position the same three registrations always had, so classic
 * consumers see no change. Calling both is harmless: data() is idempotent.
 */
final class Boot
{
    /**
     * Whether data() has already run in this request.
     *
     * Two consumers can legitimately both ask for the data layer (a theme's
     * Theme::boot() plus a site plugin's Boot::data()); registering the REST
     * routes, admin screen and meta twice would duplicate hooks, so every call
     * after the first is a no-op.
     */
    private static bool $dataBooted = false;

    /**
     * Boot the data layer only.
     *
     * Registers, in this order (the order Theme::boot() step 13 always used —
     * the golden hook snapshot in ADR-0003 depends on it):
     *   1. Tools → TAW Data: content export/import screen (capability-gated).
     *   2. GET taw/v1/content/export (capability-gated).
     *   3. REST-registered field meta for every TAW field (opt out with the
     *      taw_register_meta_in_rest filter).
     *   4. The schema registry (ADR-0004): post types, taxonomies, fieldsets
     *      and options pages defined through the taw_schema_register action.
     *      Added last so the three registrations above keep their positions.
     */
    public static function data(): void
    {
        if (self::$dataBooted) {
            return;
        }

        self::$dataBooted = true;

        (new ContentAdminScreen())->register();
        new ContentEndpoint();
        FieldMetaRegistrar::register();
        Compiler::register();
    }

    /**
     * Whether the data layer has been booted in this request.
     */
    public static function isDataBooted(): bool
    {
        return self::$dataBooted;
    }

    /**
     * Forget that data() ran, so a unit test can boot again from scratch.
     *
     * @internal For tests only. Resetting mid-request in production would let
     *           the next data() call register every hook a second time.
     */
    public static function resetForTests(): void
    {
        self::$dataBooted = false;
    }
}

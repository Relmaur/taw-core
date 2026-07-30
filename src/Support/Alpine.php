<?php

declare(strict_types=1);

namespace TAW\Support;

use TAW\Helpers\Framework;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the 'alpinejs' script handle every wp-admin Alpine component in
 * this package depends on (Metabox, OptionsPage, Icons picker, Media
 * Folders' Grid sidebar). Vendored as a static asset rather than loaded
 * from a CDN — taw/core is installed on arbitrary client sites, some
 * offline/behind restrictive CSPs/in regions where a CDN may be blocked or
 * slow, so every admin screen using Alpine inherited that fragility.
 *
 * wp_enqueue_script() is idempotent per handle, so calling this from
 * multiple independent admin_enqueue_scripts callbacks (Metabox and
 * OptionsPage both need it; Media Folders' Grid sidebar runs on upload.php,
 * a screen neither of those touches) is safe — no guard needed here.
 */
class Alpine
{
    public static function enqueue(): void
    {
        $dir = Framework::path('assets/vendor/');
        $url = Framework::url('assets/vendor/');

        wp_enqueue_script(
            'alpinejs',
            $url . 'alpine.min.js',
            [],
            filemtime($dir . 'alpine.min.js'),
            true
        );

        // Alpine's own bundle calls queueMicrotask(() => Alpine.start())
        // the moment it executes. The HTML spec runs a microtask checkpoint
        // immediately after each classic <script> finishes — before the
        // next sibling script even runs — so any script enqueued to load
        // after this one (e.g. a component registering itself via
        // Alpine.data() inside an alpine:init listener) loses the race:
        // Alpine has already scanned the DOM and found nothing registered.
        // 'defer' delays Alpine's actual execution until after the document
        // is fully parsed, which is exactly what Alpine's own docs
        // recommend for the CDN build (<script defer src="...">) — every
        // dependent script still runs immediately at its own position,
        // ahead of Alpine's now-deferred run.
        wp_script_add_data('alpinejs', 'strategy', 'defer');
    }
}

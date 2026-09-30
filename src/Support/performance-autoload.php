<?php

declare(strict_types=1);

/**
 * Escape hatch only (ADR-0003), and the one Composer `files` entry left for
 * Performance: loading the autoloader registers no hooks, because
 * Theme::boot() / bootstrapFullSite() do that. A consumer that depended on the
 * old load-time registration (without ever booting) can opt back in by
 * defining TAW_PERFORMANCE_AUTOLOAD before the autoloader runs (wp-config.php).
 * The class itself is PSR-4 autoloaded (src/Support/Performance.php).
 */
if (defined('ABSPATH') && defined('TAW_PERFORMANCE_AUTOLOAD') && TAW_PERFORMANCE_AUTOLOAD) {
    \TAW\Support\Performance::register();
}

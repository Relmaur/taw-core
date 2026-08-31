<?php

declare(strict_types=1);

namespace TAW\Hub\Telemetry;

use TAW\Helpers\Framework;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The PHP / MySQL / WordPress environment state the Hub reads to decide
 * whether a site is safe to push an update or an asset payload to.
 *
 * Pure read — no side effects. Every value is cheap to compute.
 */
final class EnvironmentReport
{
    /**
     * @return array<string, mixed>
     */
    public static function collect(): array
    {
        return [
            'taw_core'        => Framework::version(),
            'php'             => PHP_VERSION,
            'wordpress'       => get_bloginfo('version'),
            'mysql'          => self::mysqlVersion(),
            'environment'     => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
            'multisite'       => is_multisite(),
            'object_cache'    => wp_using_ext_object_cache(),
            'ext_sodium'      => extension_loaded('sodium'),
            'ext_zip'         => extension_loaded('zip'),
            'disk_free_bytes' => self::diskFreeBytes(),
            'server_time'     => time(),
        ];
    }

    private static function mysqlVersion(): ?string
    {
        global $wpdb;

        if (is_object($wpdb) && method_exists($wpdb, 'db_version')) {
            $version = $wpdb->db_version();

            return is_string($version) && $version !== '' ? $version : null;
        }

        return null;
    }

    private static function diskFreeBytes(): ?int
    {
        $path = defined('ABSPATH') ? (string) constant('ABSPATH') : __DIR__;
        $free = @disk_free_space($path);

        return is_float($free) ? (int) $free : null;
    }
}

<?php

declare(strict_types=1);

namespace TAW\Core\Block;

use TAW\Core\Block\BaseBlock;
use TAW\Core\Block\BlockRegistry;

class BlockLoader
{

    /**
     * Scan the Blocks directory recursively, instantiate every MetaBlock found,
     * and register it in the BlockRegistry.
     *
     * A directory is treated as a block if it contains a PHP file matching its
     * own name (e.g. Blocks/Hero/Hero.php). Otherwise it is treated as a group
     * folder and scanned recursively, allowing any nesting depth:
     *
     *   Blocks/Hero/Hero.php                  → TAW\Blocks\Hero\Hero
     *   Blocks/sections/Hero/Hero.php         → TAW\Blocks\sections\Hero\Hero
     *   Blocks/ui/cards/Badge/Badge.php       → TAW\Blocks\ui\cards\Badge\Badge
     */
    public static function loadAll(): void
    {
        $blocks_dir = get_template_directory() . '/Blocks';
        self::scanDirectory($blocks_dir, $blocks_dir);
    }

    private static function scanDirectory(string $dir, string $blocks_dir): void
    {
        foreach (glob($dir . '/*', GLOB_ONLYDIR) as $subdir) {
            $name = basename($subdir);
            $file = $subdir . '/' . $name . '.php';

            if (file_exists($file)) {
                // This is a block directory — derive the fully-qualified class name
                // from its path relative to Blocks/, e.g. "sections/Hero" → TAW\Blocks\sections\Hero\Hero
                $relative = ltrim(str_replace($blocks_dir, '', $subdir), '/');
                $class    = 'TAW\\Blocks\\' . str_replace('/', '\\', $relative) . '\\' . $name;

                if (!class_exists($class) || !is_subclass_of($class, BaseBlock::class)) {
                    continue;
                }

                if (is_subclass_of($class, MetaBlock::class)) {
                    foreach ($class::variations() as $variation) {
                        BlockRegistry::register(new $class($variation));
                    }
                } else {
                    // Plain Block: no registry entry needed, but call boot() so
                    // any admin-time registrations (Metaboxes, hooks) are set up
                    // on every request without requiring a template render.
                    $class::boot();
                }
            } else {
                // No matching PHP file — treat as a group folder and recurse
                self::scanDirectory($subdir, $blocks_dir);
            }
        }
    }
}

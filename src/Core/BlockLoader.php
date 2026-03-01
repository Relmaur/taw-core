<?php

declare(strict_types=1);

namespace TAW\Core;

use TAW\Core\BlockRegistry;

class BlockLoader
{

    /**
     * Scan the Blocks directory, instantiate every MetaBlock found,
     * and register it in the BlockRegistry.
     *
     * Convention: each block lives in Blocks/[Name]/{Name}.php
     * and the class is TAW\Blocks\{Name}\{Name}
     */
    public static function loadAll(): void
    {
        $blocks_dir = get_template_directory() . '/Blocks';

        foreach (glob($blocks_dir . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            $class = "TAW\\Blocks\\{$name}\\{$name}";
            $file = $dir . '/' . $name . '.php';

            if (!file_exists($file)) {
                continue;
            }

            if (!class_exists($class)) {
                continue;
            }

            if (is_subclass_of($class, MetaBlock::class)) {
                BlockRegistry::register(new $class());
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace TAW\Core\Block;

use TAW\Core\Block\BaseBlock;
use TAW\Core\Lens\SourceMap;


if (!defined('ABSPATH')) {
    exit;
}

abstract class Block extends BaseBlock
{
    /**
     * Define default values for all props this block accepts.
     * Child classes override this.
     */
    abstract protected function defaults(): array;

    /**
     * Render with explicit data passed in.
     */
    public function render(array $props = []): void
    {
        // Merge passed props over defaults - so you always have safe fallbacks
        $data = array_merge($this->defaults(), $props);
        // TAW Lens (dev only): a source-map node around the block.
        $lens = SourceMap::openBlock($this);
        try {
            $this->renderTemplate($data);
        } finally {
            SourceMap::close($lens);
        }
    }
}

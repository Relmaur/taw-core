<?php

declare(strict_types=1);

// A stand-in for WordPress's WP_Hook (unit tests run without WordPress): only
// what BaseBlock::headPassed() reads, the priority wp_head is running at.
if (!class_exists('WP_Hook', false)) {
    final class WP_Hook
    {
        public int|false $priority = false;

        public function current_priority(): int|false
        {
            return $this->priority;
        }
    }
}

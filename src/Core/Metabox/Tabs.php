<?php

declare(strict_types=1);

namespace TAW\Core\Metabox;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The attributes that make the Alpine tab bar of a metabox or options page
 * a real tab list: WAI-ARIA roles, a roving tabindex (only the active tab is
 * in the Tab order), Enter/Space to select, and Left/Right/Home/End to move
 * between tabs. The markup around them, and how the tabs look, is unchanged.
 * Before v1.59.2 the tabs were plain clickable divs, unreachable by keyboard.
 *
 * Both renderers keep `activeTab` in their own `x-data` scope; these
 * attributes only read and set it.
 */
final class Tabs
{
    /** A DOM id base from a metabox or options page id. */
    public static function idBase(string $ownerId): string
    {
        return 'taw-tabs-' . (preg_replace('/[^A-Za-z0-9_-]/', '-', $ownerId) ?? '');
    }

    /** Attributes for the element wrapping the tab titles. */
    public static function listAttributes(): string
    {
        return 'role="tablist"';
    }

    /** Attributes for tab title $index of $count. */
    public static function tabAttributes(string $idBase, int $index, int $count): string
    {
        $last = max(0, $count - 1);
        $select = "activeTab = {$index}";
        // $el.parentElement is the tab list; its children are the tabs, in order.
        $move = static fn (string $target): string => "activeTab = {$target}; \$nextTick(() => \$el.parentElement.children[activeTab].focus())";

        return implode(' ', [
            'role="tab"',
            sprintf('id="%s-tab-%d"', esc_attr($idBase), $index),
            sprintf('aria-controls="%s-panel-%d"', esc_attr($idBase), $index),
            sprintf(':aria-selected="activeTab === %d ? \'true\' : \'false\'"', $index),
            sprintf(':tabindex="activeTab === %d ? 0 : -1"', $index),
            sprintf('@keydown.enter.prevent="%s"', $select),
            sprintf('@keydown.space.prevent="%s"', $select),
            sprintf('@keydown.arrow-right.prevent="%s"', esc_attr($move("({$index} + 1) % {$count}"))),
            sprintf('@keydown.arrow-left.prevent="%s"', esc_attr($move("({$index} + {$last}) % {$count}"))),
            sprintf('@keydown.home.prevent="%s"', esc_attr($move('0'))),
            sprintf('@keydown.end.prevent="%s"', esc_attr($move((string) $last))),
        ]);
    }

    /** Attributes for the panel of tab $index. */
    public static function panelAttributes(string $idBase, int $index): string
    {
        return sprintf(
            'role="tabpanel" id="%1$s-panel-%2$d" aria-labelledby="%1$s-tab-%2$d"',
            esc_attr($idBase),
            $index,
        );
    }
}

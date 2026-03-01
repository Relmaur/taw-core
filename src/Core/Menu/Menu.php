<?php

declare(strict_types=1);

namespace TAW\Core\Menu;

use TAW\Core\Menu\MenuItem;

class Menu
{
    /** @var MenuItem[] */
    protected array $items;

    protected string $name;

    /**
     * @param MenuItem[] $items Root-level menu items (tree already built)
     */
    public function __construct(array $items, string $name = '')
    {
        $this->items = $items;
        $this->name = $name;
    }

    /**
     * Factory
     */
    public static function get(string $location): ?self
    {

        // Get registered menu locations and find our menu
        $locations = get_nav_menu_locations();

        if (!isset($locations[$location])) {
            return null;
        }

        $menu = wp_get_nav_menu_object($locations[$location]);

        if (!$menu) {
            return null;
        }

        // Get flat array of all menu items (the raw WP data)
        $rawItems = wp_get_nav_menu_items($menu->term_id);

        if (empty($rawItems)) {
            return new self([], $menu->name);
        }

        // Let WordPress resolve the "current" flags
        _wp_menu_item_classes_by_context($rawItems);

        // Build the tree
        // Create all menuitems and index by ID
        $map = [];

        foreach ($rawItems as $raw) {
            $map[$raw->ID] = new MenuItem($raw);
        }

        // Attach children to parents, collect roots
        $roots = [];

        foreach ($rawItems as $raw) {
            $parentId = (int) $raw->menu_item_parent;

            if ($parentId && isset($map[$parentId])) {

                // This item has a parent -> attach it
                $map[$parentId]->addChild($map[$raw->ID]);
            } else {

                // No parent (or parent not found) -> It's a root item
                $roots[] = $map[$raw->ID];
            }
        }

        return new self($roots, $menu->name);
    }

    /**
     * Public API
     */
    public function items(): array
    {
        return $this->items;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function hasItems(): bool
    {
        return !empty($this->items);
    }
}
<?php

declare(strict_types=1);

namespace TAW\Core\Menu;

class MenuItem
{

    // Store the raw item
    protected \WP_Post $item;

    /** @var MenuItem[] */
    protected array $children = [];

    public function __construct(\WP_Post $item, ?array $children = [])
    {
        // Store the raw item
        $this->item = $item;
    }

    // Getters
    public function title(): string
    {
        return $this->item->title;
    }

    public function url(): string
    {
        return $this->item->url;
    }

    public function target(): string
    {
        return $this->item->target ?: '_self';
    }

    public function openInNewTab(): bool
    {
        return $this->target() === '_blank';
    }

    // Tree
    public function addChild(MenuItem $child): void
    {
        $this->children[] = $child;
    }

    /** @return MenuItem[] */
    public function children(): array
    {
        return $this->children;
    }

    public function hasChildren(): bool
    {
        return !empty($this->children);
    }

    // State
    public function isActive(): bool
    {
        return (bool) $this->item->current;
    }

    public function isActiveParent(): bool
    {
        return (bool) $this->item->current_item_parent;
    }

    public function isActiveAncestor(): bool
    {
        return (bool) $this->item->current_item_ancestor;
    }

    public function isInActiveTrail(): bool
    {
        return $this->isActive() || $this->isActiveParent() || $this->isActiveAncestor();
    }

    // Classes

    public function classes(): array
    {
        $wpAuto = [
            'menu-item',
            'menu-item-type-post_type',
            'menu-item-type-custom',
            'menu-item-type-taxonomy',
            'menu-item-object-page',
            'menu-item-object-post',
            'menu-item-object-category',
            'menu-item-object-custom',
            'current-menu-item',
            'current-menu-parent',
            'current-menu-ancestor',
            'menu-item-has-children',
        ];

        return array_values(array_filter(
            $this->item->classes,
            fn($class) => $class !== '' && !in_array($class, $wpAuto, true)
        ));
    }

    public function wpClasses(): array
    {
        return array_filter($this->item->classes, fn($class) => $class !== '');
    }

    // Metadata
    public function objectType(): string
    {
        return $this->item->object;
    }

    public function objectId(): int
    {
        return (int) $this->item->object_id;
    }

    public function description(): string
    {
        return $this->item->description ?? '';
    }

    public function wpPost(): \WP_Post
    {
        return $this->item;
    }
}

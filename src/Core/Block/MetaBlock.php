<?php

declare(strict_types=1);

namespace TAW\Core\Block;

use TAW\Core\Metabox\Metabox;

abstract class MetaBlock extends BaseBlock
{
    protected string $variation = '';
    protected string $baseId   = '';

    public function __construct(string $variation = '')
    {
        parent::__construct();

        $this->baseId = $this->id;

        if ($variation !== '') {
            $this->variation = $variation;
            $this->id        = $this->id . '--' . $variation;
        }

        $this->registerMetaboxes();
    }

    /**
     * Get the variation string for this block instance.
     */
    public function getVariation(): string
    {
        return $this->variation;
    }

    /**
     * All variations share the same physical assets, so use the base ID
     * as the WP handle and deduplication key.
     */
    protected function getAssetId(): string
    {
        return $this->baseId;
    }

    /**
     * Declare which variations of this block should be registered.
     * Override to return multiple variation strings (e.g. ['', 'footer']).
     * An empty string means the default instance with no suffix.
     *
     * @return string[]
     */
    public static function variations(): array
    {
        return [''];
    }

    /**
     * Define and register metaboxes for this block.
     */
    abstract protected function registerMetaboxes(): void;

    /**
     * Gather template data from post meta.
     */
    abstract protected function getData(int $postId): array;

    /**
     * Render this block for a given post
     */
    public function render(?int $postId = null): void
    {
        $postId = $postId ?? get_the_ID();
        if (!$postId) return;

        $data = $this->getData($postId);
        $this->renderTemplate($data);
    }

    /**
     * Helpers
     */
    protected function getMeta(int $postId, string $fieldId, string $prefix = '_taw_'): mixed
    {
        return Metabox::get($postId, $fieldId, $prefix);
    }

    protected function getImageUrl(int $postId, string $fieldId, string $size = 'full'): string
    {
        return Metabox::get_image_url($postId, $fieldId, $size);
    }

    /**
     * Get a repeater field value for a given post.
     */
    protected function getRepeater(int $postId, string $fieldId, string $prefix = '_taw_'): array
    {
        return Metabox::get_repeater($postId, $fieldId, $prefix);
    }
}

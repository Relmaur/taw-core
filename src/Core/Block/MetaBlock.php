<?php

declare(strict_types=1);

namespace TAW\Core\Block;

use TAW\Core\Metabox\Metabox;

abstract class MetaBlock extends BaseBlock
{
    protected string $variation = '';

    public function __construct(string $variation = '')
    {
        parent::__construct();

        if ($variation !== '') {
            $this->variation = $variation;
            $this->id        = $this->id . '--' . $variation;
        }

        $this->registerMetaboxes();
    }

    public function getVariation(): string
    {
        return $this->variation;
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
}

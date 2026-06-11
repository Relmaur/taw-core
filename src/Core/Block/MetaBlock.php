<?php

declare(strict_types=1);

namespace TAW\Core\Block;

use TAW\Core\Editor\VisualEditor;
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

        add_action('init', [$this, 'initMetaboxes']);
    }

    public function initMetaboxes(): void
    {
        // Tag all Metabox instances created inside registerMetaboxes() with this block's ID.
        Metabox::setCurrentBlockId($this->id);
        $this->registerMetaboxes();
        Metabox::setCurrentBlockId(null);
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
    abstract protected function getData(int|false $postId): array;

    /**
     * Render this block for a given post.
     * When the visual editor is active, wraps output in a section container
     * so the editor can identify and highlight editable regions.
     */
    public function render(?int $postId = null): void
    {
        $postId = $postId !== null ? $postId : get_the_ID();
        if (!$postId) return;

        $data = $this->getData($postId);

        if (VisualEditor::isActive()) {
            echo '<div data-taw-block-section="' . esc_attr($this->id) . '">';
            $this->renderTemplate($data);
            echo '</div>';
        } else {
            $this->renderTemplate($data);
        }
    }

    /**
     * Helpers
     */
    protected function getMeta(int|false $postId, string $fieldId, string $prefix = '_taw_'): mixed
    {
        if (!$postId) return null;
        return Metabox::get($postId, $fieldId, $prefix);
    }

    protected function getImageUrl(int|false $postId, string $fieldId, string $size = 'full'): string
    {
        if (!$postId) return '';
        return Metabox::get_image_url($postId, $fieldId, $size);
    }

    /**
     * Get a repeater field value for a given post.
     */
    protected function getRepeater(int|false $postId, string $fieldId, string $prefix = '_taw_'): array
    {
        if (!$postId) return [];
        return Metabox::get_repeater($postId, $fieldId, $prefix);
    }
}

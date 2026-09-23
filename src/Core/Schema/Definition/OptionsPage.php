<?php

declare(strict_types=1);

namespace TAW\Core\Schema\Definition;

use TAW\Core\Schema\Field;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * A site-wide settings page — compiled into a
 * `new \TAW\Core\OptionsPage\OptionsPage([...])` at init:8, so it gets the
 * existing admin UI and storage (one wp_options row per field, `_taw_{id}`).
 *
 *   Schema::optionsPage('site')
 *       ->title('Site settings')
 *       ->fields([Field::text('company_phone')->label('Phone')]);
 *
 * Read values with OptionsPage::get('company_phone'), as before.
 */
final class OptionsPage extends Definition
{
    public const KIND = 'options_page';

    private string $title = '';

    /** @var list<Field|array<string, mixed>> */
    private array $fields = [];

    /** @var array<string, mixed> */
    private array $extra = [];

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function menuTitle(string $menuTitle): self
    {
        return $this->with(['menu_title' => $menuTitle]);
    }

    /** Capability needed to see and save the page. Default 'manage_options'. */
    public function capability(string $capability): self
    {
        return $this->with(['capability' => $capability]);
    }

    /**
     * @param list<Field|array<string, mixed>> $fields
     */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * Any other OptionsPage config key (prefix, tabs, icon, position, …).
     *
     * @param array<string, mixed> $config
     */
    public function with(array $config): self
    {
        unset($config['id'], $config['fields']);
        $this->extra = array_replace($this->extra, $config);

        return $this;
    }

    public function problems(): array
    {
        return $this->fields === []
            ? [sprintf('Options page "%s" has no fields.', $this->key())]
            : [];
    }

    /**
     * The exact config for `new OptionsPage(...)`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_replace($this->extra, [
            'id'     => $this->key(),
            'title'  => $this->title !== '' ? $this->title : $this->key(),
            'fields' => Field::normalizeList($this->fields),
        ]);
    }
}

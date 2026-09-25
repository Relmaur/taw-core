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

    /** Values of `rest` (the engine reads them from here: this class loads before boot). */
    public const REST_MODES = ['private', 'public'];

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
     * Expose the page over REST (off by default): 'private' adds its options
     * to /wp/v2/settings (manage_options); 'public' also serves them
     * read-only at GET taw/v1/options/<key>, to anyone — every field.
     */
    public function rest(string $mode): self
    {
        return $this->with(['rest' => $mode]);
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
        $problems = $this->fields === []
            ? [sprintf('Options page "%s" has no fields.', $this->key())]
            : [];

        $rest = $this->extra['rest'] ?? null;
        if ($rest !== null && !in_array($rest, self::REST_MODES, true)) {
            $problems[] = sprintf('Options page "%s": rest must be "private" or "public".', $this->key());
        }

        return $problems;
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

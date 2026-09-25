<?php

declare(strict_types=1);

namespace TAW\Core\Schema\Definition;

use TAW\Core\DataPanel\Ui;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * Site-wide data-layer settings (ADR-0007). One per site, so the key is
 * always "site"; a child theme or PHP replaces the parent theme's through the
 * usual schema precedence.
 *
 *   Schema::settings()->fieldsetUi('panel');
 *
 * JSON: {"version": 1, "kind": "settings", "key": "site", "fieldsetUi": "panel"}
 */
final class SiteSettings extends Definition
{
    public const KIND = 'settings';

    public const KEY = 'site';

    /** JSON keys this kind accepts (besides the common ones). */
    public const KEYS = ['fieldsetUi'];

    private ?string $fieldsetUi = null;

    /**
     * Where fieldsets appear in the block editor unless they say otherwise:
     * "panel" (the TAW Data sidebar) or "metabox" (the default).
     * TAW_DATA_UI in wp-config.php replaces it for one install.
     */
    public function fieldsetUi(string $ui): self
    {
        $this->fieldsetUi = $ui;

        return $this;
    }

    public function fieldsetUiValue(): ?string
    {
        return $this->fieldsetUi;
    }

    public function problems(): array
    {
        return array_map(
            static fn (string $error): string => 'Site settings ' . $error,
            self::validate($this->toArray())
        );
    }

    /**
     * Errors for the settings keys, as "<json pointer>: <message>".
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function validate(array $data): array
    {
        if (array_key_exists('fieldsetUi', $data) && !in_array($data['fieldsetUi'], Ui::VALUES, true)) {
            return ['/fieldsetUi: must be one of ' . implode(', ', Ui::VALUES)];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fieldsetUi === null ? [] : ['fieldsetUi' => $this->fieldsetUi];
    }

    protected static function keyProblem(string $key): ?string
    {
        return $key === self::KEY ? null : 'a site has one settings definition, and its key is "site"';
    }
}

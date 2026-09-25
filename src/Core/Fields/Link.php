<?php

declare(strict_types=1);

namespace TAW\Core\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A link field's value: URL, text and "open in a new tab" (ADR-0009). With
 * no URL there's no link: every method returns '' and `exists()` is false.
 */
final class Link implements \Stringable
{
    public function __construct(
        private readonly string $url = '',
        private readonly string $label = '',
        private readonly bool $newTab = false
    ) {
    }

    /**
     * @param array{url?: string, label?: string, new_tab?: bool}|null $link A decoded link (FieldCodec).
     */
    public static function from(?array $link): self
    {
        return $link === null
            ? new self()
            : new self((string) ($link['url'] ?? ''), (string) ($link['label'] ?? ''), (bool) ($link['new_tab'] ?? false));
    }

    public function exists(): bool
    {
        return $this->url !== '';
    }

    /** The URL, unescaped (escape with esc_url() in an attribute). */
    public function url(): string
    {
        return $this->url;
    }

    /** The link text, unescaped; the URL when no text was given. */
    public function label(): string
    {
        return $this->label !== '' ? $this->label : $this->url;
    }

    public function newTab(): bool
    {
        return $this->newTab;
    }

    /**
     * The <a> element, escaped; `target="_blank" rel="noopener"` for a new tab.
     *
     * @param array<string, string> $attributes Extra attributes (class, aria-label, …).
     */
    public function html(array $attributes = []): string
    {
        if (!$this->exists()) {
            return '';
        }

        $attributes = ['href' => $this->url] + $attributes;
        if ($this->newTab) {
            $attributes += ['target' => '_blank', 'rel' => 'noopener'];
        }

        $html = '';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . esc_attr((string) $name) . '="' . ($name === 'href' ? esc_url((string) $value) : esc_attr((string) $value)) . '"';
        }

        return '<a' . $html . '>' . esc_html($this->label()) . '</a>';
    }

    public function __toString(): string
    {
        return $this->html();
    }
}

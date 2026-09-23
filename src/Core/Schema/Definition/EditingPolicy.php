<?php

declare(strict_types=1);

namespace TAW\Core\Schema\Definition;

use TAW\Core\Editing\Presets;
use TAW\Core\Editing\Rules;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * A site's editing policy: how far the block editor is locked down, layer by
 * layer (ADR-0005). One per site, so the key is always "site"; a child theme
 * or PHP replaces the parent theme's through the usual schema precedence.
 *
 *   Schema::editing()
 *       ->preset('structured')
 *       ->layer('features', ['customHtml' => true])
 *       ->content('page', ['allow' => ['core/*'], 'lock' => 'contentOnly'])
 *       ->content('post', 'open');
 *
 * Like every definition this is inert data. Editing\Resolver turns it into
 * the effective Editing\Policy, and Boot::editing() applies that.
 */
final class EditingPolicy extends Definition
{
    public const KIND = 'editing';

    public const KEY = 'site';

    private ?string $preset = null;

    private ?string $bypassCapability = null;

    /** @var array<string, mixed> layer => level name or overrides */
    private array $layers = [];

    /**
     * The level every layer starts from: open, guided, structured or locked.
     * TAW_EDITING_PRESET in wp-config.php replaces it for one install.
     */
    public function preset(string $level): self
    {
        $this->preset = $level;

        return $this;
    }

    /**
     * The capability that exempts a user from the content, site and features
     * layers (default taw_unlock_editing). Users named in
     * TAW_EDITING_BYPASS_USERS are exempt too.
     */
    public function bypass(string $capability): self
    {
        $this->bypassCapability = $capability;

        return $this;
    }

    /**
     * Set one layer to a level ("guided"), or override its settings
     * (['customHtml' => true], optionally with a 'level' to start from).
     *
     * @param string|array<string, mixed> $value
     */
    public function layer(string $layer, string|array $value): self
    {
        $this->layers[$layer] = $value;

        return $this;
    }

    /**
     * The content rule for one post type: a level, or overrides (allow,
     * template, lock, newPostsOnly, optionally 'level').
     *
     * @param string|array<string, mixed> $rule
     */
    public function content(string $postType, string|array $rule): self
    {
        $content = $this->layers['content'] ?? [];

        // A level for the whole layer means "this level on the default post
        // types"; spell that out so a per-type rule can sit next to it.
        if (is_string($content)) {
            $content = array_fill_keys(Presets::DEFAULT_TARGETS, $content);
        }

        $content[$postType] = $rule;
        $this->layers['content'] = $content;

        return $this;
    }

    public function presetLevel(): ?string
    {
        return $this->preset;
    }

    public function bypassCapability(): ?string
    {
        return $this->bypassCapability;
    }

    /**
     * @return array<string, mixed>
     */
    public function layers(): array
    {
        return $this->layers;
    }

    public function problems(): array
    {
        return array_map(
            static fn (string $error): string => 'Editing policy ' . $error,
            Rules::validatePolicy($this->toArray())
        );
    }

    /**
     * The policy in its JSON shape (preset, bypass, layers).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $policy = [];

        if ($this->preset !== null) {
            $policy['preset'] = $this->preset;
        }
        if ($this->bypassCapability !== null) {
            $policy['bypass'] = ['capability' => $this->bypassCapability];
        }
        if ($this->layers !== []) {
            $policy['layers'] = $this->layers;
        }

        return $policy;
    }

    protected static function keyProblem(string $key): ?string
    {
        return $key === self::KEY ? null : 'a site has one editing policy, and its key is "site"';
    }
}

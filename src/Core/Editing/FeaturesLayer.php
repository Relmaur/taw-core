<?php

declare(strict_types=1);

namespace TAW\Core\Editing;

// No ABSPATH guard: pure class definition (see Editing\Presets).

/**
 * The editor-features layer (ADR-0005): turns off editor features a locked
 * user shouldn't have. Custom HTML is handled by ContentLayer, because it's
 * an allow-list question.
 *
 *   codeEditor      → editor setting codeEditingEnabled
 *   blockLocking    → editor setting canLockBlocks (the lock/unlock UI)
 *   openverse       → editor setting enableOpenverseMediaCategory
 *   blockDirectory  → the "install blocks from the directory" inserter panel
 *   corePatterns    → the patterns WordPress itself ships
 *   remotePatterns  → patterns fetched from wordpress.org
 */
final class FeaturesLayer
{
    public function __construct(
        private readonly Policy $policy,
        private readonly Bypass $bypass,
    ) {
    }

    /**
     * Called at init:7 (Editing::apply()), before core registers its
     * patterns at init:10.
     */
    public function register(): void
    {
        add_filter('block_editor_settings_all', [$this, 'editorSettings'], 20, 2);
        add_filter('should_load_remote_block_patterns', [$this, 'loadRemotePatterns'], 20);
        add_action('enqueue_block_editor_assets', [$this, 'maybeRemoveBlockDirectory'], 1);

        if (!$this->allowed('corePatterns') && !$this->bypass->active()) {
            remove_theme_support('core-block-patterns');
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function editorSettings(array $settings, mixed $context = null): array
    {
        if ($this->bypass->active()) {
            return $settings;
        }

        if (!$this->allowed('codeEditor')) {
            $settings['codeEditingEnabled'] = false;
        }
        if (!$this->allowed('blockLocking')) {
            $settings['canLockBlocks'] = false;
        }
        if (!$this->allowed('openverse')) {
            $settings['enableOpenverseMediaCategory'] = false;
        }

        return $settings;
    }

    public function loadRemotePatterns(mixed $load): bool
    {
        if (!$this->allowed('remotePatterns') && !$this->bypass->active()) {
            return false;
        }

        return (bool) $load;
    }

    /**
     * Runs just before core would enqueue the block directory's script.
     */
    public function maybeRemoveBlockDirectory(): void
    {
        if (!$this->allowed('blockDirectory') && !$this->bypass->active()) {
            remove_action('enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets');
        }
    }

    private function allowed(string $feature): bool
    {
        return $this->policy->features[$feature] ?? true;
    }
}

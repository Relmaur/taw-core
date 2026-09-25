<?php

declare(strict_types=1);

namespace TAW\Core\Editing;

use TAW\Core\Schema\Definition\EditingPolicy;
use TAW\Core\Schema\Registry;

// No ABSPATH guard: pure class definition (see Editing\Presets).

/**
 * Tools → TAW Editing: a read-only view of the effective editing policy
 * (ADR-0005 § 8). The policy lives in code and JSON, never in the database,
 * so there is nothing to save here. The screen answers "what is locked,
 * why, and am I exempt?".
 */
final class EditingAdminScreen
{
    public const SLUG = 'taw-editing';

    /** @var \Closure(): list<string> */
    private \Closure $registeredBlocks;

    /**
     * @param (\Closure(): list<string>)|null $registeredBlocks Injectable for tests.
     */
    public function __construct(
        private readonly Policy $policy,
        private readonly Bypass $bypass,
        ?\Closure $registeredBlocks = null,
    ) {
        $this->registeredBlocks = $registeredBlocks
            ?? static fn (): array => array_map('strval', array_keys(\WP_Block_Type_Registry::get_instance()->get_all_registered()));
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
    }

    public function addPage(): void
    {
        add_management_page(
            __('TAW Editing', 'taw-core'),
            __('TAW Editing', 'taw-core'),
            'manage_options',
            self::SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Everything the screen shows, as plain data.
     *
     * @return array{
     *     preset: string,
     *     presetSource: string,
     *     youBypass: bool,
     *     bypass: array{capability: string, users: list<string>},
     *     themeBlocks: list<string>,
     *     layers: array<string, array<string, bool>>,
     *     content: array<string, array<string, mixed>>,
     *     warnings: list<string>
     * }
     */
    public function report(?string $definitionSource): array
    {
        $presetSource = match ($this->policy->presetSource) {
            'constant'   => 'TAW_EDITING_PRESET (wp-config.php)',
            'definition' => $definitionSource ?? 'editing policy',
            default      => __('default (no editing policy sets one)', 'taw-core'),
        };

        $content = [];
        foreach ($this->policy->contentPostTypes() as $postType) {
            $content[$postType] = $this->policy->content($postType);
        }

        return [
            'preset'       => $this->policy->preset,
            'presetSource' => $presetSource,
            'youBypass'    => $this->bypass->active(),
            'bypass'       => ['capability' => $this->bypass->capability, 'users' => $this->bypass->users],
            'themeBlocks'  => $this->policy->themeBlocks,
            'layers'       => [
                'site'     => $this->policy->site,
                'design'   => $this->policy->design,
                'features' => $this->policy->features,
            ],
            'content'      => $content,
            'warnings'     => $this->warnings($content),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $content
     * @return list<string>
     */
    private function warnings(array $content): array
    {
        $warnings = $this->policy->warnings;

        if ($this->bypass->users === [] && $this->locksAnything($content)) {
            $warnings[] = sprintf(
                /* translators: %s: capability name. */
                __('TAW_EDITING_BYPASS_USERS is empty, so only users granted the %s capability are exempt. Add your developer logins to it in wp-config.php.', 'taw-core'),
                $this->bypass->capability
            );
        }

        // Only question allow lists someone wrote: the presets' curated list
        // is known-good, and flagging a core block a plugin unregistered
        // would just be noise. themeBlocks are in every list, so they're
        // checked once, not per post type.
        $registered = ($this->registeredBlocks)();
        foreach ($this->policy->themeBlocks as $glob) {
            if (array_filter($registered, static fn (string $name): bool => Blocks::matches($name, [$glob])) === []) {
                $warnings[] = sprintf(
                    /* translators: %s: block name or glob. */
                    __('"%s" in themeBlocks matches no registered block.', 'taw-core'),
                    $glob
                );
            }
        }
        foreach ($content as $postType => $rule) {
            $allow = is_array($rule['allow']) ? array_diff($rule['allow'], Presets::CURATED_BLOCKS, $this->policy->themeBlocks) : [];
            foreach ($allow as $glob) {
                $matched = array_filter($registered, static fn (string $name): bool => Blocks::matches($name, [(string) $glob]));
                if ($matched === []) {
                    $warnings[] = sprintf(
                        /* translators: 1: block name or glob, 2: post type. */
                        __('"%1$s" in the %2$s allow list matches no registered block.', 'taw-core'),
                        $glob,
                        $postType
                    );
                }
            }
        }

        return $warnings;
    }

    /**
     * @param array<string, array<string, mixed>> $content
     */
    private function locksAnything(array $content): bool
    {
        foreach ([$this->policy->site, $this->policy->design, $this->policy->features] as $layer) {
            if (in_array(false, $layer, true)) {
                return true;
            }
        }
        foreach ($content as $rule) {
            if ($rule['allow'] !== null || $rule['lock'] !== false || $rule['template'] !== null) {
                return true;
            }
        }

        return false;
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'taw-core'));
        }

        $source = Registry::instance()->sourceOf(EditingPolicy::KIND . ':' . EditingPolicy::KEY)?->describe();
        $report = $this->report($source);
        $yes    = esc_html__('allowed', 'taw-core');
        $no     = esc_html__('locked', 'taw-core');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('TAW Editing', 'taw-core'); ?></h1>
            <p class="description">
                <?php esc_html_e('The editing policy in effect for this site (read-only). Change it in the theme\'s taw-schema/editing.json, or pick a preset for this install with TAW_EDITING_PRESET in wp-config.php.', 'taw-core'); ?>
            </p>

            <?php foreach ($report['warnings'] as $warning) : ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html($warning); ?></p></div>
            <?php endforeach; ?>

            <table class="widefat striped" style="max-width:48rem;margin-top:1rem">
                <tbody>
                    <tr><th><?php esc_html_e('Preset', 'taw-core'); ?></th><td><strong><?php echo esc_html($report['preset']); ?></strong></td></tr>
                    <tr><th><?php esc_html_e('Set by', 'taw-core'); ?></th><td><code><?php echo esc_html($report['presetSource']); ?></code></td></tr>
                    <tr><th><?php esc_html_e('You', 'taw-core'); ?></th><td><?php echo $report['youBypass'] ? esc_html__('are exempt (bypass)', 'taw-core') : esc_html__('are locked like everyone else', 'taw-core'); ?></td></tr>
                    <tr><th><?php esc_html_e('Theme blocks', 'taw-core'); ?></th><td><?php echo $report['themeBlocks'] === [] ? '—' : '<code>' . esc_html(implode(', ', $report['themeBlocks'])) . '</code> ' . esc_html__('(added to every allow list)', 'taw-core'); ?></td></tr>
                    <tr><th><?php esc_html_e('Bypass', 'taw-core'); ?></th><td><code><?php echo esc_html($report['bypass']['capability']); ?></code> · TAW_EDITING_BYPASS_USERS: <?php echo esc_html($report['bypass']['users'] === [] ? '—' : implode(', ', $report['bypass']['users'])); ?></td></tr>
                </tbody>
            </table>

            <?php foreach ($report['layers'] as $layer => $settings) : ?>
                <h2><?php echo esc_html(ucfirst($layer)); ?></h2>
                <table class="widefat striped" style="max-width:48rem">
                    <tbody>
                        <?php foreach ($settings as $setting => $allowed) : ?>
                            <tr><th><code><?php echo esc_html($setting); ?></code></th><td><?php echo $allowed ? $yes : '<strong>' . $no . '</strong>'; ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>

            <h2><?php esc_html_e('Content, per post type', 'taw-core'); ?></h2>
            <p class="description"><?php esc_html_e('Post types not listed keep the normal editor.', 'taw-core'); ?></p>
            <table class="widefat striped" style="max-width:48rem">
                <thead><tr><th><?php esc_html_e('Post type', 'taw-core'); ?></th><th><?php esc_html_e('Allowed blocks', 'taw-core'); ?></th><th><?php esc_html_e('Lock', 'taw-core'); ?></th><th><?php esc_html_e('Starting template', 'taw-core'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($report['content'] as $postType => $rule) : ?>
                        <tr>
                            <td><code><?php echo esc_html($postType); ?></code></td>
                            <td><?php echo esc_html(is_array($rule['allow']) ? implode(', ', $rule['allow']) : __('all', 'taw-core')); ?></td>
                            <td><?php echo esc_html($rule['lock'] === false ? __('none', 'taw-core') : (string) $rule['lock']); ?></td>
                            <td><?php echo esc_html($rule['template'] === null ? '—' : sprintf(_n('%d block', '%d blocks', count($rule['template']), 'taw-core'), count($rule['template']))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

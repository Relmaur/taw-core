<?php

declare(strict_types=1);

namespace TAW\Core\Editing;

use TAW\Helpers\Framework;

// No ABSPATH guard: pure class definition (see Editing\Presets).

/**
 * The content layer (ADR-0005): which blocks can be inserted in each post
 * type, the starting template for new posts, and the template lock. Also
 * enforces the features layer's Custom HTML setting, since it's an
 * allow-list question too.
 *
 * Enforced in the editor (allowed_block_types_all, block_editor_settings_all)
 * and on save (rest_pre_insert_{post_type}): a save that adds a block the
 * rule doesn't allow is rejected. Blocks already in the saved post are left
 * alone, so tightening a policy never makes existing content unsaveable.
 * Template locks are editor guardrails only (ADR-0005 § 5).
 *
 * A rule's allowBound blocks (ADR-0010 decision 10) are the exception to
 * the allow list: they can be inserted, but a save may only add them bound
 * to a TAW field. The editor learns them from the `tawAllowBound` editor
 * setting and offers them as "Field …" variations (the bindings script).
 *
 * `lock: contentOnly` is sent as templateLock "all" plus an editor script
 * that puts every block in the contentOnly editing mode: since WordPress
 * 7.1 the editor ignores a page-level contentOnly lock (it only applies
 * inside "section" blocks), so the setting alone locks nothing.
 */
final class ContentLayer
{
    public const CONTENT_ONLY_HANDLE = 'taw-editing-content-only';

    /** @var \Closure(): list<string> */
    private \Closure $registeredBlocks;

    /**
     * @param (\Closure(): list<string>)|null $registeredBlocks Names of every registered block (injectable for tests).
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
        add_filter('allowed_block_types_all', [$this, 'allowedBlockTypes'], 20, 2);
        add_filter('block_editor_settings_all', [$this, 'editorSettings'], 20, 2);
        add_action('rest_api_init', [$this, 'registerSaveChecks']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueContentOnly']);
    }

    /**
     * allowed_block_types_all: the rule's allow list for the post being
     * edited, minus Custom HTML when the features layer turns it off. In
     * editors without a post (Site Editor, widgets) only Custom HTML is
     * affected.
     *
     * @param bool|array<int, string> $allowed true = every block.
     * @return bool|list<string>
     */
    public function allowedBlockTypes(bool|array $allowed, mixed $context): bool|array
    {
        if ($allowed === false || $this->bypass->active()) {
            return $allowed;
        }

        $rule       = $this->ruleFor($context);
        $allow      = $rule['allow'] ?? null;
        $allowBound = $rule['allowBound'] ?? [];
        $customHtml = $this->policy->features['customHtml'] ?? true;

        if ($allow === null && $customHtml) {
            return $allowed;
        }

        $names = is_array($allowed) ? array_values(array_filter($allowed, 'is_string')) : ($this->registeredBlocks)();

        return array_values(array_filter(
            $names,
            static fn (string $name): bool => Blocks::isAllowed($name, $allow, $customHtml)
                || Blocks::isAllowedBound($name, $allowBound, $customHtml)
        ));
    }

    /**
     * block_editor_settings_all: starting template and template lock for
     * the post being edited.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function editorSettings(array $settings, mixed $context): array
    {
        $post = self::postOf($context);
        if ($post === null || $this->bypass->active()) {
            return $settings;
        }

        $rule = $this->policy->content($post->post_type);

        // Only brand-new posts get the template by default: applying it to
        // an existing post makes Gutenberg offer to "reset" content that no
        // longer matches (the ml-theme ThemeMode lesson).
        if ($rule['template'] !== null && ($rule['newPostsOnly'] === false || $post->post_status === 'auto-draft')) {
            $settings['template'] = $rule['template'];
        }

        if ($rule['lock'] !== false) {
            // contentOnly is enforced as "all" + enqueueContentOnly() (see the class docblock).
            $settings['templateLock'] = $rule['lock'] === 'contentOnly' ? 'all' : $rule['lock'];
        }

        $boundOnly = $this->boundOnly($rule);
        if ($boundOnly !== []) {
            $settings['tawAllowBound'] = $boundOnly;
        }

        return $settings;
    }

    /**
     * The registered blocks a rule allows only bound to a TAW field.
     *
     * @param array<string, mixed> $rule
     * @return list<string>
     */
    private function boundOnly(array $rule): array
    {
        $allowBound = $rule['allowBound'] ?? [];
        if ($allowBound === [] || $rule['allow'] === null) {
            return [];
        }
        $customHtml = $this->policy->features['customHtml'] ?? true;

        return array_values(array_filter(
            ($this->registeredBlocks)(),
            static fn (string $name): bool => !Blocks::isAllowed($name, $rule['allow'], $customHtml)
                && Blocks::isAllowedBound($name, $allowBound, $customHtml)
        ));
    }

    /**
     * enqueue_block_editor_assets: the content-only script, for a locked user
     * editing a post whose rule is lock: contentOnly.
     */
    public function enqueueContentOnly(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->base !== 'post' || $screen->post_type === '') {
            return;
        }
        if ($this->policy->content($screen->post_type)['lock'] !== 'contentOnly' || $this->bypass->active()) {
            return;
        }

        wp_enqueue_script(
            self::CONTENT_ONLY_HANDLE,
            Framework::url('assets/editing-content-only.js'),
            ['wp-data', 'wp-block-editor'],
            Framework::version(),
            true
        );
    }

    /**
     * Hook the save check onto every post type the REST API can write.
     */
    public function registerSaveChecks(): void
    {
        foreach (get_post_types(['show_in_rest' => true]) as $postType) {
            add_filter(
                'rest_pre_insert_' . $postType,
                fn (mixed $prepared): mixed => $this->checkSave($prepared, (string) $postType),
                10,
                1
            );
        }
    }

    /**
     * rest_pre_insert_{post_type}: reject blocks this save *adds* that the
     * rule doesn't allow. Covers autosaves too (they go through the same
     * filter via the parent controller).
     *
     * An allowBound block is counted instead: the save may not add unbound
     * ones (more unbound blocks of that name than the saved post has), so a
     * post that already holds a bound paragraph can't slip a plain one in,
     * and existing unbound content still saves.
     */
    public function checkSave(mixed $prepared, string $postType): mixed
    {
        if (!$prepared instanceof \stdClass || !isset($prepared->post_content) || !is_string($prepared->post_content)) {
            return $prepared;
        }
        if ($this->bypass->active()) {
            return $prepared;
        }

        $rule       = $this->policy->content($postType);
        $allow      = $rule['allow'];
        $allowBound = $rule['allowBound'] ?? [];
        $customHtml = $this->policy->features['customHtml'] ?? true;
        if ($allow === null && $customHtml) {
            return $prepared;
        }

        $existing = !empty($prepared->ID) ? (string) get_post_field('post_content', (int) $prepared->ID) : '';
        $new      = parse_blocks($prepared->post_content);
        $old      = parse_blocks($existing);
        $added    = array_diff(Blocks::namesIn($new), Blocks::namesIn($old));

        $refused = array_values(array_filter(
            $added,
            static fn (string $name): bool => !Blocks::isAllowed($name, $allow, $customHtml)
                && !Blocks::isAllowedBound($name, $allowBound, $customHtml)
        ));

        $unbound = [];
        if ($allowBound !== []) {
            $before = Blocks::unboundCounts($old);
            foreach (Blocks::unboundCounts($new) as $name => $count) {
                if ($count > ($before[$name] ?? 0)
                    && !Blocks::isAllowed($name, $allow, $customHtml)
                    && Blocks::isAllowedBound($name, $allowBound, $customHtml)
                ) {
                    $unbound[] = (string) $name;
                }
            }
        }

        if ($refused === [] && $unbound === []) {
            return $prepared;
        }

        if ($refused === []) {
            return new \WP_Error(
                'taw_editing_block_not_bound',
                sprintf(
                    /* translators: 1: comma-separated block names, 2: post type. */
                    __('These blocks can only be added here connected to a TAW field: %1$s. Connect them (block toolbar → TAW field) or remove them. Editing policy for "%2$s".', 'taw-core'),
                    implode(', ', $unbound),
                    $postType
                ),
                ['status' => 400, 'blocks' => $unbound]
            );
        }

        return new \WP_Error(
            'taw_editing_block_not_allowed',
            sprintf(
                /* translators: 1: comma-separated block names, 2: post type. */
                __('These blocks can\'t be added here: %1$s. The editing policy for "%2$s" doesn\'t allow them.', 'taw-core'),
                implode(', ', $refused),
                $postType
            ),
            ['status' => 400, 'blocks' => $refused]
        );
    }

    /**
     * The content rule for the editor's post, or null outside the post
     * editor.
     *
     * @return array<string, mixed>|null
     */
    private function ruleFor(mixed $context): ?array
    {
        $post = self::postOf($context);

        return $post === null ? null : $this->policy->content($post->post_type);
    }

    private static function postOf(mixed $context): ?\WP_Post
    {
        return is_object($context) && isset($context->post) && $context->post instanceof \WP_Post ? $context->post : null;
    }
}

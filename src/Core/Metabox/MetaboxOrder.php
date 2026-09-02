<?php

declare(strict_types=1);

namespace TAW\Core\Metabox;


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Forces a fixed, non-draggable metabox order on the post-edit screen.
 *
 * By default WordPress renders metaboxes in registration order within each
 * context/priority bucket, then lets any user override that via drag-and-drop
 * (persisted per-user in the `meta-box-order_{screen}` user option). This
 * class collapses a screen's boxes into an explicit order and disables both
 * sources of drift.
 *
 * Usage — explicit order:
 *   MetaboxOrder::lock('page', ['hero_settings', 'video_settings', 'faq_settings']);
 *
 * Usage — derive order from the page template's block render sequence:
 *   MetaboxOrder::lockFromTemplate();
 *
 * The latter statically scans the template file that will actually render
 * the post for `BlockRegistry::render('block_id')` calls (in source order —
 * the file is never executed) and maps each block ID to the metabox ID(s) it
 * registered via Metabox::getFieldRegistry(). Template resolution mirrors
 * WordPress's own hierarchy via Metabox::templateCandidatesForPost(): an
 * explicitly-selected page template (`_wp_page_template`) wins, then the
 * filename conventions that need no Page Attributes selection —
 * `front-page.php` (static front page), `home.php` (posts page), and
 * `page-{slug}.php`. Posts matching none of these are left unordered.
 */
class MetaboxOrder
{
    /** @var array<string, true> Screens already wired for drag-lock, so preventReordering() only fires once each. */
    private static array $lockedScreens = [];

    /**
     * Force an explicit metabox order for a screen.
     *
     * @param string   $screen Post type / screen ID (e.g. 'page').
     * @param string[] $order  Metabox IDs in the desired top-to-bottom order.
     *                         Boxes not listed keep their relative position and are appended after.
     */
    public static function lock(string $screen, array $order): void
    {
        add_action('add_meta_boxes', function (string $postType) use ($screen, $order): void {
            if ($postType === $screen) {
                self::applyOrder($screen, $order);
            }
        }, 999);

        self::preventReordering($screen);
    }

    /**
     * Derive and lock metabox order per-post from its page template's
     * BlockRegistry::render() call sequence.
     *
     * @param string $screen Post type / screen ID. Default 'page'.
     */
    public static function lockFromTemplate(string $screen = 'page'): void
    {
        add_action('add_meta_boxes', function (string $postType, $post = null) use ($screen): void {
            if ($postType !== $screen || !($post instanceof \WP_Post)) {
                return;
            }

            $order = self::orderForPost($post);
            if (!empty($order)) {
                self::applyOrder($screen, $order);
            }
        }, 999, 2);

        self::preventReordering($screen);
    }

    /**
     * Resolve the metabox order for a post from the template that will
     * actually render it.
     *
     * Walks Metabox::templateCandidatesForPost() — the same WordPress
     * template-hierarchy resolution Metabox::postMatchesTemplate() uses — in
     * priority order and locks onto the first candidate that resolves to a
     * real file in the theme. That covers an explicitly-selected page
     * template (`_wp_page_template`) as well as the filename conventions that
     * need no Page Attributes selection: `front-page.php`, `home.php`, and
     * `page-{slug}.php`.
     */
    private static function orderForPost(\WP_Post $post): array
    {
        $file = false;
        foreach (Metabox::templateCandidatesForPost($post) as $candidate) {
            $file = locate_template([$candidate]);
            if ($file) {
                break;
            }
        }

        if (!$file) {
            return [];
        }

        $blockIds = self::blockOrderFromTemplateFile($file);
        return self::metaboxOrderForBlocks($blockIds);
    }

    /**
     * Extract the ordered, de-duplicated list of block IDs passed to
     * BlockRegistry::render() in a template file, via static source scan.
     */
    private static function blockOrderFromTemplateFile(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        preg_match_all('/BlockRegistry::render\s*\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Map an ordered list of block IDs to the metabox ID(s) each registered,
     * via the field registry's block_id tagging (see MetaBlock::initMetaboxes()).
     */
    private static function metaboxOrderForBlocks(array $blockIds): array
    {
        $order  = [];
        $fields = Metabox::getFieldRegistry();

        foreach ($blockIds as $blockId) {
            foreach ($fields as $field) {
                if (($field['block_id'] ?? null) !== $blockId) {
                    continue;
                }

                $metaboxId = $field['metabox_id'];
                if (!in_array($metaboxId, $order, true)) {
                    $order[] = $metaboxId;
                }
            }
        }

        return $order;
    }

    /**
     * Reorder a screen's metaboxes in-place inside $wp_meta_boxes.
     *
     * Only touches contexts that contain at least one box from $order —
     * boxes there are collapsed into a single 'high' priority bucket in the
     * requested sequence, boxes not in $order are appended afterwards in
     * their original relative order. Contexts with no matching boxes are
     * left untouched.
     */
    private static function applyOrder(string $screen, array $order): void
    {
        global $wp_meta_boxes;

        if (empty($wp_meta_boxes[$screen]) || empty($order)) {
            return;
        }

        foreach ($wp_meta_boxes[$screen] as $context => $priorities) {
            $boxesById = [];
            foreach ($priorities as $boxes) {
                foreach ($boxes as $id => $box) {
                    if ($box === false) {
                        continue;
                    }
                    $boxesById[$id] = $box;
                }
            }

            $hasMatch = false;
            foreach ($order as $id) {
                if (isset($boxesById[$id])) {
                    $hasMatch = true;
                    break;
                }
            }
            if (!$hasMatch) {
                continue;
            }

            $ordered = [];
            foreach ($order as $id) {
                if (isset($boxesById[$id])) {
                    $ordered[$id] = $boxesById[$id];
                    unset($boxesById[$id]);
                }
            }
            foreach ($boxesById as $id => $box) {
                $ordered[$id] = $box;
            }

            $wp_meta_boxes[$screen][$context] = [
                'high'    => $ordered,
                'core'    => [],
                'default' => [],
                'low'     => [],
            ];
        }
    }

    /**
     * Disable drag-and-drop reordering for a screen: discard any saved
     * per-user order and turn off the sortable UI.
     */
    private static function preventReordering(string $screen): void
    {
        if (isset(self::$lockedScreens[$screen])) {
            return;
        }
        self::$lockedScreens[$screen] = true;

        add_filter("get_user_option_meta-box-order_{$screen}", '__return_false');

        add_action('admin_footer-post.php', fn () => self::printDisableScript($screen));
        add_action('admin_footer-post-new.php', fn () => self::printDisableScript($screen));
    }

    /**
     * Print inline JS that disables the postbox sortable UI on the locked screen.
     */
    private static function printDisableScript(string $screen): void
    {
        $current = get_current_screen();
        if (!$current || $current->id !== $screen) {
            return;
        }
        ?>
        <script>
        jQuery(function ($) {
            $('.meta-box-sortables').sortable('disable');
            $('.postbox .hndle, .postbox .handlediv').css('cursor', 'default');
        });
        </script>
        <?php
    }
}

/**
 * allowBound in the editor (taw/core ADR-0010 decision 10). A locked-down post
 * type's rule can allow some blocks only bound to a TAW field; the server
 * sends their names as the `tawAllowBound` post editor setting (not set for bypass
 * users or other post types). Here:
 *
 * - each gets a "Field …" inserter variation that replaces the plain block;
 * - saving is locked, with a notice, while the edit adds unbound ones, the
 *   same count the server's save check makes (so the save isn't refused).
 *
 * The field picker opening for a fresh "Field …" block is in menu.tsx.
 */
import { parse, registerBlockVariation } from '@wordpress/blocks';
import { dispatch, select, subscribe } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { boundVariation, newlyUnbound, unboundCounts, type BlockNode, type Config } from './logic';

const LOCK = 'taw-allow-bound';
const NOTICE = 'taw-allow-bound';

export function registerAllowBound(config: Config): void {
    let boundOnly: string[] | null = null;
    let savedContent: string | null = null;
    let saved: Record<string, number> = {};
    let lastBlocks: unknown = null;
    let locked = false;

    const unsubscribe = subscribe(() => {
        const blockEditor = select('core/block-editor');
        const editor = select('core/editor');

        if (boundOnly === null) {
            // core/editor keeps every setting; the block editor's copy only has the keys it knows.
            const setting = editor?.getEditorSettings?.()?.tawAllowBound;
            if (!Array.isArray(setting)) return;
            boundOnly = setting.filter((name): name is string => typeof name === 'string');
            if (boundOnly.length === 0) {
                unsubscribe();
                return;
            }
            for (const name of boundOnly) {
                const variation = boundVariation(config.source, name);
                if (variation) registerBlockVariation(name, variation);
            }
        }

        const content = editor?.getCurrentPostAttribute?.('content');
        const blocks = blockEditor.getBlocks();
        if (typeof content !== 'string' || (content === savedContent && blocks === lastBlocks)) return;
        if (content !== savedContent) {
            savedContent = content;
            saved = unboundCounts(config.source, parse(content) as unknown as BlockNode[]);
        }
        lastBlocks = blocks;

        const added = newlyUnbound(boundOnly, saved, unboundCounts(config.source, blocks as BlockNode[]));
        if (added.length > 0 && !locked) {
            locked = true;
            dispatch('core/editor').lockPostSaving(LOCK);
        } else if (added.length === 0 && locked) {
            locked = false;
            dispatch('core/editor').unlockPostSaving(LOCK);
            dispatch('core/notices').removeNotice(NOTICE);
        }
        if (added.length > 0) {
            const titles = added.map((name) => select('core/blocks').getBlockType(name)?.title ?? name);
            dispatch('core/notices').createWarningNotice(
                sprintf(
                    /* translators: %s: comma-separated block titles. */
                    __('Connect these blocks to a TAW field (block toolbar → TAW field) or remove them before saving: %s.', 'taw-core'),
                    titles.join(', '),
                ),
                { id: NOTICE, isDismissible: false },
            );
        }
    });
}

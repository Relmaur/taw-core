import { autop, removep } from '@wordpress/autop';
import { createBlock, rawHandler, serialize } from '@wordpress/blocks';
import type { BlockInstance } from '@wordpress/blocks';
import type { FieldDescriptor } from '../types';

/**
 * wysiwyg values are plain HTML as wp_editor() saves it (paragraphs as blank
 * lines), never block markup.
 * The mini editor converts them to blocks when it opens and back to HTML
 * when the user applies a change (ADR-0007, plan P3).
 */

/** The blocks a wysiwyg field offers by default. */
export const DEFAULT_BLOCKS = [
    'core/paragraph',
    'core/heading',
    'core/list',
    'core/list-item',
    'core/quote',
    'core/image',
    'core/buttons',
    'core/button',
    'core/separator',
    'core/table',
];

/** A `teeny` field: text formatting only, like wp_editor()'s teeny toolbar. */
export const TEENY_BLOCKS = ['core/paragraph', 'core/list', 'core/list-item', 'core/quote'];

const MEDIA_BLOCKS = ['core/image'];

/** The blocks the editor offers for a field (`blocks`, then teeny, then the default set). */
export function allowedBlocks(field: FieldDescriptor): string[] {
    if (Array.isArray(field.blocks) && field.blocks.length > 0) {
        return field.blocks.filter((name): name is string => typeof name === 'string');
    }
    const base = field.teeny ? TEENY_BLOCKS : DEFAULT_BLOCKS;
    return field.media_buttons === false ? base.filter((name) => !MEDIA_BLOCKS.includes(name)) : base;
}

/**
 * Stored HTML → blocks. wpautop first (TinyMCE saves paragraphs as blank
 * lines, not <p>), then the same conversion as "Convert to blocks":
 * shortcodes become shortcode blocks, and markup no block represents lands
 * in a Custom HTML block, so nothing is dropped.
 */
export function toBlocks(html: string): BlockInstance[] {
    const blocks = html.trim() === '' ? [] : rawHandler({ HTML: autop(html) });
    return blocks.length > 0 ? blocks : [createBlock('core/paragraph')];
}

/** Block comment delimiters: `<!-- wp:name {...} -->`, `<!-- /wp:name -->`, `<!-- wp:name /-->`. */
const DELIMITER = /<!-- \/?wp:[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?(?: \{[\s\S]*?\})? \/?-->\n?/g;

/**
 * Blocks → the HTML to store, in the classic editor's own format: each
 * block's saved markup without the `<!-- wp: -->` delimiters (nested blocks
 * included), then removep() as wp_editor() does when it saves, so paragraphs
 * are blank lines, not <p>. Templates that print the value raw and ones that
 * run wpautop() both show it as before (plan P3 round-trip report). The
 * serializer escapes `--` inside attributes, so a delimiter can't end early.
 */
export function toHtml(blocks: BlockInstance[]): string {
    const html = serialize(blocks.filter((block) => !isEmptyParagraph(block)))
        .replace(DELIMITER, '')
        // List items are inner blocks: one per line, no blank lines between them.
        .replace(/<\/li>\s+(?=<li[\s>])/g, '</li>\n')
        .replace(/<\/li>\s+(?=<\/[ou]l>)/g, '</li>');
    return removep(html).trim();
}

function isEmptyParagraph(block: BlockInstance): boolean {
    const content = block.attributes.content;
    return (
        block.name === 'core/paragraph' &&
        block.innerBlocks.length === 0 &&
        (content === undefined || content === null || String(content).trim() === '')
    );
}

/** A short plain-text preview of stored HTML, for the sidebar. */
export function excerpt(html: string, words = 24): string {
    const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
    doc.querySelectorAll('br, p, li, h1, h2, h3, h4, h5, h6, blockquote, div, td').forEach((node) => node.append(' '));
    const text = (doc.body.textContent ?? '').replace(/\s+/g, ' ').trim();
    const list = text === '' ? [] : text.split(' ');
    return list.length > words ? `${list.slice(0, words).join(' ')}…` : text;
}

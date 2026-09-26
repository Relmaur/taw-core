/**
 * Chips in rich text (ADR-0011/0012), inserted the way core footnotes insert
 * theirs: an atomic object in the rich-text value.
 *
 * "Insert at cursor" runs from the block toolbar, outside the RichText, so it
 * edits the attribute the block editor's selection points at: parse its HTML,
 * insert the object at the selection, write it back, put the caret after it.
 */
import { dispatch, select } from '@wordpress/data';
import { create, insertObject, toHTMLString } from '@wordpress/rich-text';
import { chipObject, type TagArgs } from './tags';

interface SelectionPoint {
    clientId?: string;
    attributeKey?: string;
    offset?: number;
}

/** The rich-text attribute and range the caret is in, when it's in this block. */
export function caretIn(clientId: string): { attributeKey: string; start: number; end: number } | null {
    const editor = select('core/block-editor');
    const start = editor.getSelectionStart() as SelectionPoint;
    const end = editor.getSelectionEnd() as SelectionPoint;
    if (start.clientId !== clientId || !start.attributeKey || typeof start.offset !== 'number') return null;
    const sameAttribute =
        end.clientId === clientId && end.attributeKey === start.attributeKey && typeof end.offset === 'number';
    const a = start.offset;
    const b = sameAttribute ? (end.offset as number) : a;
    return { attributeKey: start.attributeKey, start: Math.min(a, b), end: Math.max(a, b) };
}

/** Insert a chip at the block's caret (replacing a selected range). False when there's no caret. */
export function insertChip(clientId: string, args: TagArgs, text: string): boolean {
    const caret = caretIn(clientId);
    if (!caret) return false;

    const html = String(
        (select('core/block-editor').getBlockAttributes(clientId) as Record<string, unknown>)[caret.attributeKey] ?? '',
    );
    const value = insertObject(create({ html }), chipObject(args, text), caret.start, caret.end);
    const { updateBlockAttributes, selectionChange } = dispatch('core/block-editor');
    updateBlockAttributes(clientId, { [caret.attributeKey]: toHTMLString({ value }) });
    selectionChange(clientId, caret.attributeKey, caret.start + 1, caret.start + 1);
    return true;
}

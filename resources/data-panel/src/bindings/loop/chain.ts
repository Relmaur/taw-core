/**
 * The TAW Loops around a block (ADR-0014), outermost first. Previews send
 * them so the server reads each loop's first item: that's the item the
 * editor edits, so `@row.*` and `{"row": …}` bindings show real values.
 */
import { select } from '@wordpress/data';
import { payload, type LoopAttributes, type LoopPayload } from './data';

export const LOOP = 'taw/loop';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Select = (store: string) => any;

/**
 * The loops enclosing a block (not the block itself), outermost first. Inside
 * a `useSelect` (bindings' getValues), pass its `select` so changes re-render.
 */
export function loopChain(clientId: string | null | undefined, read: Select = select): LoopAttributes[] {
    if (!clientId) return [];
    const editor = read('core/block-editor');
    const ids: string[] = editor?.getBlockParentsByBlockName?.(clientId, LOOP) ?? [];
    return ids
        .map((id) => editor.getBlockAttributes(id) as LoopAttributes | null)
        .filter((attributes): attributes is LoopAttributes => Boolean(attributes?.source?.type));
}

/** What previews send for a block: its enclosing loops' sources and options. */
export function loopsFor(clientId: string | null | undefined, read: Select = select): LoopPayload[] {
    return loopChain(clientId, read).map(payload);
}

/**
 * Previews for chips and expressions (ADR-0012), through the same batched
 * POST taw/v1/bindings/preview as bound blocks: `kind: "tag"` gives a chip's
 * text, `kind: "expr"` an expression's value and errors, `kind: "condition"`
 * whether a condition holds for the edited post (ADR-0013).
 */
import { select } from '@wordpress/data';
import type { ExpressionError } from './expression';
import type { PreviewItem } from './logic';
import type { ConditionError } from './conditions';
import type { TagArgs } from './tags';

type Fetch = (item: PreviewItem) => Promise<unknown>;

let fetchPreview: Fetch | null = null;

/** index.ts hands over its batched fetch. */
export function setPreviewFetch(fetch: Fetch): void {
    fetchPreview = fetch;
}

/** The edited post (a template previews its type's latest post on the server). */
function context(): { postId: number; postType: string } {
    const editor = select('core/editor');
    const id: unknown = editor?.getCurrentPostId?.();
    const type: unknown = editor?.getCurrentPostType?.();
    return { postId: typeof id === 'number' ? id : 0, postType: typeof type === 'string' ? type : '' };
}

function item(kind: 'tag' | 'expr' | 'condition', args: object): PreviewItem {
    const { postId, postType } = context();
    return {
        key: `${kind}|${postId}|${postType}|${JSON.stringify(args)}`,
        kind,
        args: args as unknown as PreviewItem['args'],
        block: '',
        attribute: '',
        postId,
        postType,
    };
}

/** A chip's plain-text value, or null. */
export async function previewTag(args: TagArgs): Promise<string | null> {
    if (!fetchPreview) return null;
    try {
        const value = await fetchPreview(item('tag', args));
        return typeof value === 'string' ? value : null;
    } catch {
        return null;
    }
}

/** An expression's value and the server's errors. */
export async function previewExpression(expr: string): Promise<{ value: string; errors: ExpressionError[] }> {
    if (!fetchPreview) return { value: '', errors: [] };
    try {
        const result = (await fetchPreview(item('expr', { expr }))) as { value?: unknown; errors?: unknown } | null;
        return {
            value: typeof result?.value === 'string' ? result.value : '',
            errors: Array.isArray(result?.errors) ? (result.errors as ExpressionError[]) : [],
        };
    } catch {
        return { value: '', errors: [] };
    }
}

/** Whether a condition holds for the edited post; null when it can't be asked. */
export async function previewCondition(
    condition: unknown,
): Promise<{ shown: boolean; errors: ConditionError[] } | null> {
    if (!fetchPreview) return null;
    try {
        const result = (await fetchPreview(item('condition', { if: condition }))) as {
            shown?: unknown;
            errors?: unknown;
        } | null;
        if (!result || typeof result.shown !== 'boolean') return null;
        return { shown: result.shown, errors: Array.isArray(result.errors) ? (result.errors as ConditionError[]) : [] };
    } catch {
        return null;
    }
}

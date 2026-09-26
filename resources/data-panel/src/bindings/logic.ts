/**
 * The `taw/field` Block Bindings source for the editor (taw/core ADR-0010,
 * data layer Phase 4 Step 2). Pure logic: the WordPress glue is in index.ts.
 *
 * - getValues: each bound attribute's preview, from the `taw/bindings` store,
 *   which asks PHP (POST taw/v1/bindings/preview) with the same resolver the
 *   front end uses. Until it answers, text attributes show the field's label.
 * - getFieldsList: the fields the Attributes panel offers, from
 *   window.tawBindings.fields (EditorFields in PHP).
 *
 * Read-only: no setValues. Values are edited in the metabox or data panel.
 */
import { __ } from '@wordpress/i18n';

export type From = 'post' | 'option' | 'term' | 'user';

export interface BindingArgs {
    field: string;
    from?: From;
    sub?: string;
    size?: string;
}

export interface FieldEntry {
    label: string;
    args: BindingArgs;
    type: 'string' | 'number';
    fieldType?: string;
}

export interface Config {
    source: string;
    route: string;
    fields: {
        post: Record<string, FieldEntry[]>;
        option: FieldEntry[];
        term: FieldEntry[];
        user: FieldEntry[];
    };
}

export interface BlockContext {
    postId?: number;
    postType?: string;
}

/** One preview request: a bound attribute of a block, in its post context. */
export interface PreviewItem {
    key: string;
    args: BindingArgs;
    block: string;
    attribute: string;
    postId: number;
    postType: string;
}

/** Attributes whose placeholder is the field's label (text shown in the canvas). */
const TEXT_ATTRIBUTES = ['content', 'text', 'caption', 'alt', 'title'];

export function previewItem(args: BindingArgs, block: string, attribute: string, context: BlockContext): PreviewItem {
    const postId = typeof context.postId === 'number' ? context.postId : 0;
    const postType = typeof context.postType === 'string' ? context.postType : '';
    const normalized: BindingArgs = { field: args.field, from: args.from ?? 'post' };
    if (args.sub) normalized.sub = args.sub;
    if (args.size) normalized.size = args.size;

    // Options don't depend on the post; one key serves every block.
    const where = normalized.from === 'option' ? '' : `${postId}|${postType}`;

    return {
        key: `${block}|${attribute}|${where}|${JSON.stringify(normalized)}`,
        args: normalized,
        block,
        attribute,
        postId,
        postType,
    };
}

function groupLabel(from: From): string {
    switch (from) {
        case 'option':
            return __('Options', 'taw-core');
        case 'term':
            return __('Term', 'taw-core');
        case 'user':
            return __('Author', 'taw-core');
        default:
            return __('Post', 'taw-core');
    }
}

function labelled(entries: FieldEntry[], from: From): FieldEntry[] {
    return entries.map((entry) => ({ ...entry, label: `${groupLabel(from)}: ${entry.label}` }));
}

/** The post fields for a post type, or every post type's (deduplicated) in a template without one. */
function postFields(config: Config, postType: string | undefined): FieldEntry[] {
    if (postType && config.fields.post[postType]) {
        return config.fields.post[postType];
    }
    const seen = new Set<string>();
    return Object.values(config.fields.post)
        .flat()
        .filter((entry) => {
            const key = JSON.stringify([entry.args, entry.type]);
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
}

export function fieldsList(config: Config, context: BlockContext): FieldEntry[] {
    return [
        ...labelled(postFields(config, context.postType), 'post'),
        ...labelled(config.fields.option, 'option'),
        ...labelled(config.fields.term, 'term'),
        ...labelled(config.fields.user, 'user'),
    ];
}

/** The label a binding shows while its preview loads, or when the field is empty. */
export function placeholder(config: Config, args: BindingArgs, attribute: string): string | undefined {
    if (!TEXT_ATTRIBUTES.includes(attribute)) return undefined;
    const from = args.from ?? 'post';
    const pool = from === 'post' ? Object.values(config.fields.post).flat() : config.fields[from];
    const entry = pool.find((e) => e.args.field === args.field && (e.args.sub ?? '') === (args.sub ?? ''));
    return `${groupLabel(from)}: ${entry?.label ?? args.field}`;
}

type Select = (store: string) => {
    getBlockName?: (clientId: string) => string | null;
    getValue?: (item: PreviewItem) => unknown;
};

export const STORE = 'taw/bindings';

/** The object handed to registerBlockBindingsSource(). */
export function source(config: Config) {
    return {
        name: config.source,
        usesContext: ['postId', 'postType'],
        getValues({
            select,
            context,
            bindings,
            clientId,
        }: {
            select: Select;
            context: BlockContext;
            bindings: Record<string, { args: BindingArgs }>;
            clientId: string;
        }): Record<string, unknown> {
            const block = select('core/block-editor').getBlockName?.(clientId) ?? '';
            const values: Record<string, unknown> = {};
            for (const [attribute, binding] of Object.entries(bindings)) {
                const args = binding?.args;
                if (!args || typeof args.field !== 'string') continue;
                const value = select(STORE).getValue?.(previewItem(args, block, attribute, context ?? {}));
                values[attribute] =
                    value === undefined || value === null || value === ''
                        ? placeholder(config, args, attribute)
                        : value;
            }
            return values;
        },
        getFieldsList({ context }: { context: BlockContext }): FieldEntry[] {
            return fieldsList(config, context ?? {});
        },
    };
}

/**
 * Collects preview requests made in the same tick and sends them as one
 * POST, so a page of bound blocks is one request.
 */
export function batcher(send: (items: PreviewItem[]) => Promise<Record<string, unknown>>, max = 200) {
    let queue: { item: PreviewItem; resolve: (v: unknown) => void; reject: (e: unknown) => void }[] = [];
    let scheduled = false;

    const flush = () => {
        scheduled = false;
        const batch = queue;
        queue = [];
        for (let i = 0; i < batch.length; i += max) {
            const chunk = batch.slice(i, i + max);
            send(chunk.map((entry) => entry.item)).then(
                (values) => chunk.forEach((entry) => entry.resolve(values[entry.item.key] ?? null)),
                (error) => chunk.forEach((entry) => entry.reject(error)),
            );
        }
    };

    return (item: PreviewItem): Promise<unknown> =>
        new Promise((resolve, reject) => {
            queue.push({ item, resolve, reject });
            if (!scheduled) {
                scheduled = true;
                queueMicrotask(flush);
            }
        });
}

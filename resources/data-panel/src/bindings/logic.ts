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
    if (args.field.trim() === '') return __('Choose a TAW field', 'taw-core');
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

/** Same values as the previous object: return that one, so useSelect sees a stable result. */
function stable(cache: Map<string, Record<string, unknown>>, key: string, next: Record<string, unknown>) {
    const previous = cache.get(key);
    const keys = Object.keys(next);
    if (
        previous &&
        keys.length === Object.keys(previous).length &&
        keys.every((k) => Object.is(previous[k], next[k]))
    ) {
        return previous;
    }
    cache.set(key, next);
    return next;
}

/** The object handed to registerBlockBindingsSource(). */
export function source(config: Config) {
    // WordPress calls these inside useSelect: equal results must be the same object.
    const lists = new Map<string, FieldEntry[]>();
    const values = new Map<string, Record<string, unknown>>();

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
            clientId?: string;
        }): Record<string, unknown> {
            const block = (clientId && select('core/block-editor').getBlockName?.(clientId)) || '';
            const result: Record<string, unknown> = {};
            for (const [attribute, binding] of Object.entries(bindings)) {
                const args = binding?.args;
                if (!args || typeof args.field !== 'string') continue;
                // No field picked yet (a fresh "Field …" block): nothing to ask the server.
                const value =
                    args.field.trim() === ''
                        ? undefined
                        : select(STORE).getValue?.(previewItem(args, block, attribute, context ?? {}));
                result[attribute] =
                    value === undefined || value === null || value === ''
                        ? placeholder(config, args, attribute)
                        : value;
            }
            // The Attributes panel's field menu calls this without a clientId, once per field:
            // the args and the post are part of the key, or those calls would share one entry.
            const where = `${context?.postId ?? ''}|${context?.postType ?? ''}`;
            const args = Object.entries(bindings).map(([attribute, b]) => [attribute, b?.args]);
            return stable(values, `${clientId ?? ''}|${where}|${JSON.stringify(args)}`, result);
        },
        getFieldsList({ context }: { context: BlockContext }): FieldEntry[] {
            const key = context?.postType ?? '';
            let list = lists.get(key);
            if (!list) {
                list = fieldsList(config, context ?? {});
                lists.set(key, list);
            }
            return list;
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

/* ------------------------------------------------------------------ */
/* "Connect to TAW field…" in the block's Options menu (menu.tsx).      */
/* ------------------------------------------------------------------ */

export type Bindings = Record<string, { source: string; args: BindingArgs }>;

const TEXT_BLOCKS = ['core/paragraph', 'core/heading', 'core/list-item'];
const TEXT_TYPES = [
    'text',
    'textarea',
    'wysiwyg',
    'select',
    'number',
    'range',
    'color',
    'icon',
    'datepicker',
    'url',
    'link',
    'post_select',
];

/** Blocks the Options menu offers to connect. */
export const CONNECTABLE_BLOCKS = [...TEXT_BLOCKS, 'core/button', 'core/image', 'core/post-date'];

/**
 * The attributes one click binds when a field is picked for a block, or null
 * when the field doesn't fit it. Mirrors the server's AttributeMap.
 */
export function planFor(source: string, block: string, entry: FieldEntry): Bindings | null {
    if (entry.type !== 'string') return null;
    const type = entry.fieldType ?? 'text';
    const bind = (...attributes: string[]): Bindings =>
        Object.fromEntries(attributes.map((attribute) => [attribute, { source, args: entry.args }]));

    if (TEXT_BLOCKS.includes(block)) {
        return TEXT_TYPES.includes(type) ? bind('content') : null;
    }
    switch (block) {
        case 'core/button':
            if (type === 'link') return bind('url', 'text', 'linkTarget', 'rel');
            if (type === 'url') return bind('url');
            if (type === 'post_select') return bind('url', 'text');
            return TEXT_TYPES.includes(type) ? bind('text') : null;
        case 'core/image':
            return type === 'image' || type === 'post_select' ? bind('id', 'url', 'alt') : null;
        case 'core/post-date':
            return type === 'datepicker' ? bind('datetime') : null;
        default:
            return null;
    }
}

/** The fields the menu offers for a block, labelled like the Attributes panel. */
export function candidatesFor(config: Config, block: string, context: BlockContext): FieldEntry[] {
    return fieldsList(config, context).filter((entry) => planFor(config.source, block, entry) !== null);
}

/** The block's bindings with every `taw/field` one removed (null when none are left). */
export function withoutTawBindings(source: string, bindings: Bindings | undefined): Bindings | undefined {
    const kept = Object.fromEntries(Object.entries(bindings ?? {}).filter(([, binding]) => binding?.source !== source));
    return Object.keys(kept).length > 0 ? kept : undefined;
}

/** The block's `metadata` after picking a field: its TAW bindings replaced by the field's plan (null if it doesn't fit). */
export function connectedMetadata(
    source: string,
    block: string,
    metadata: { bindings?: Bindings; [key: string]: unknown },
    entry: FieldEntry,
): { bindings?: Bindings; [key: string]: unknown } | null {
    const plan = planFor(source, block, entry);
    if (!plan) return null;
    return { ...metadata, bindings: { ...(withoutTawBindings(source, metadata.bindings) ?? {}), ...plan } };
}

/** The args of the field a block is connected to (its first `taw/field` binding), if any. */
export function connectedArgs(source: string, bindings: Bindings | undefined): BindingArgs | null {
    const binding = Object.values(bindings ?? {}).find((b) => b?.source === source);
    return binding ? binding.args : null;
}

export function sameField(a: BindingArgs | null, b: BindingArgs): boolean {
    return (
        a !== null &&
        a.field === b.field &&
        (a.from ?? 'post') === (b.from ?? 'post') &&
        (a.sub ?? '') === (b.sub ?? '')
    );
}

/** Whether a block's bindings connect it to a TAW field (one with a field picked). Mirrors Editing\\Blocks::isBound(). */
export function isBound(source: string, bindings: Bindings | undefined): boolean {
    return Object.values(bindings ?? {}).some(
        (b) => b?.source === source && typeof b.args?.field === 'string' && b.args.field.trim() !== '',
    );
}

/** A `taw/field` binding is there, but no field is picked yet (a fresh "Field …" block). */
export function awaitingField(source: string, bindings: Bindings | undefined): boolean {
    return Object.values(bindings ?? {}).some((b) => b?.source === source) && !isBound(source, bindings);
}

/* ------------------------------------------------------------------ */
/* allowBound (editing policies): blocks a locked-down post type only   */
/* takes bound to a TAW field. The server sends their names as the      */
/* `tawAllowBound` editor setting (Editing\\ContentLayer).               */
/* ------------------------------------------------------------------ */

/** The attribute a fresh "Field …" block binds before a field is picked, and its inserter title. */
const BOUND_VARIATIONS: Record<string, { attribute: string; title: () => string }> = {
    'core/paragraph': { attribute: 'content', title: () => __('Field text', 'taw-core') },
    'core/heading': { attribute: 'content', title: () => __('Field heading', 'taw-core') },
    'core/list-item': { attribute: 'content', title: () => __('Field list item', 'taw-core') },
    'core/button': { attribute: 'text', title: () => __('Field button', 'taw-core') },
    'core/image': { attribute: 'url', title: () => __('Field image', 'taw-core') },
    'core/post-date': { attribute: 'datetime', title: () => __('Field date', 'taw-core') },
};

/**
 * The inserter variation that stands in for a bound-only block. `isDefault`
 * makes the inserter list it instead of the plain block; inserting it adds a
 * `taw/field` binding with no field yet, and the field picker opens.
 */
export function boundVariation(source: string, block: string): Record<string, unknown> | null {
    const spec = BOUND_VARIATIONS[block];
    if (!spec) return null;

    return {
        name: 'taw-field',
        title: spec.title(),
        description: __('Shows a TAW field. Pick the field after inserting it.', 'taw-core'),
        icon: 'database',
        isDefault: true,
        scope: ['inserter'],
        attributes: { metadata: { bindings: { [spec.attribute]: { source, args: { field: '' } } } } },
        isActive: (attributes: { metadata?: { bindings?: Bindings } }) =>
            Object.values(attributes.metadata?.bindings ?? {}).some((b) => b?.source === source),
    };
}

export interface BlockNode {
    name: string;
    attributes: { metadata?: { bindings?: Bindings }; [key: string]: unknown };
    innerBlocks?: BlockNode[];
}

/** Unbound blocks per name, inner blocks included. Mirrors Editing\\Blocks::unboundCounts(). */
export function unboundCounts(source: string, blocks: BlockNode[], counts: Record<string, number> = {}) {
    for (const block of blocks) {
        if (!isBound(source, block.attributes?.metadata?.bindings)) {
            counts[block.name] = (counts[block.name] ?? 0) + 1;
        }
        if (block.innerBlocks?.length) unboundCounts(source, block.innerBlocks, counts);
    }
    return counts;
}

/**
 * The bound-only blocks this edit adds unbound: more unbound blocks of that
 * name than the saved post has. The same count the server's save check makes.
 */
export function newlyUnbound(boundOnly: string[], saved: Record<string, number>, edited: Record<string, number>): string[] {
    return boundOnly.filter((name) => (edited[name] ?? 0) > (saved[name] ?? 0));
}

/**
 * The TAW Loop in the editor (taw/core ADR-0014): attribute types, the loop
 * values the popup offers inside an item (`@row.*`, `@loop.*`), and the
 * starting designs. Pure: the WordPress glue is in the .tsx files.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { Condition } from '../conditions';
import type { BindingArgs, Config, FieldEntry, From } from '../logic';
import { valueOptions, type ValueOption } from '../tags';

export type SourceType = 'repeater' | 'related' | 'images' | 'terms' | 'query';

export interface SubField {
    id: string;
    label: string;
    type: string;
    subs?: SubField[];
}

/** A field a loop can read (Loop\EditorData). */
export interface SourceEntry {
    field: string;
    from: From;
    type: 'repeater' | 'related' | 'images';
    label: string;
    fieldset?: string;
    subs?: SubField[];
    postType?: string;
}

export interface LoopConfig {
    sources: {
        post: Record<string, SourceEntry[]>;
        option: SourceEntry[];
        term: SourceEntry[];
        user: SourceEntry[];
    };
    postTypes: { name: string; label: string }[];
    taxonomies: { name: string; label: string; postTypes: string[] }[];
}

export interface LoopSource {
    type?: SourceType;
    field?: string;
    from?: From | 'row';
    taxonomy?: string;
    scope?: 'post' | 'all';
    orderBy?: string;
    order?: 'ASC' | 'DESC';
    hideEmpty?: boolean;
    postType?: string;
    terms?: Record<string, number[] | 'current'>;
    excludeCurrent?: boolean;
    author?: number | 'current';
    search?: string;
    sticky?: '' | 'only' | 'exclude';
}

export interface LoopAttributes {
    loopId?: number;
    source: LoopSource;
    order: { by?: string; dir?: 'asc' | 'desc'; as?: 'text' | 'number' | 'date' };
    limit: number;
    offset: number;
    perPage: number;
    filter?: Condition;
    layout: { type?: 'list' | 'grid'; columns?: number; gap?: string };
    ordered: boolean;
}

/** What a preview sends for each enclosing loop (the server re-reads its items). */
export type LoopPayload = Pick<LoopAttributes, 'source' | 'order' | 'limit' | 'offset' | 'filter'>;

export function payload(attributes: LoopAttributes): LoopPayload {
    const { source, order, limit, offset, filter } = attributes;
    return filter ? { source, order, limit, offset, filter } : { source, order, limit, offset };
}

export function sourceLabel(type: SourceType | undefined): string {
    switch (type) {
        case 'repeater':
            return __('Repeater rows', 'taw-core');
        case 'related':
            return __('Related posts', 'taw-core');
        case 'images':
            return __('Images', 'taw-core');
        case 'terms':
            return __('Terms', 'taw-core');
        case 'query':
            return __('Posts (query)', 'taw-core');
        default:
            return __('No source', 'taw-core');
    }
}

export function sourceHelp(type: SourceType): string {
    switch (type) {
        case 'repeater':
            return __('One item per row of a repeater field, e.g. a book’s awards.', 'taw-core');
        case 'related':
            return __('The posts picked in a post select field, e.g. books in this series.', 'taw-core');
        case 'images':
            return __('The images of a files or image field, e.g. a gallery.', 'taw-core');
        case 'terms':
            return __('Categories, tags or any taxonomy: the post’s own, or all of them.', 'taw-core');
        default:
            return __('Posts by type, terms, author or search, like core’s Query Loop.', 'taw-core');
    }
}

export const SOURCE_TYPES: SourceType[] = ['repeater', 'related', 'query', 'terms', 'images'];

/**
 * The fields a field source can read here: the edited post type's, options,
 * term and author fields, and, inside another loop's repeater item, that
 * row's repeaters (`from: row`).
 */
export function fieldSources(
    config: Config,
    type: 'repeater' | 'related' | 'images',
    postType: string | undefined,
    outerRepeaterSubs: SubField[] | null,
): { value: string; label: string; entry: SourceEntry | SubField; from: From | 'row' }[] {
    const loop = config.loop;
    if (!loop) return [];
    const post =
        postType && loop.sources.post[postType] ? loop.sources.post[postType] : Object.values(loop.sources.post).flat();
    const place = (from: From): string => {
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
    };
    const list: { value: string; label: string; entry: SourceEntry | SubField; from: From | 'row' }[] = [];
    const seen = new Set<string>();
    for (const entry of [...post, ...loop.sources.option, ...loop.sources.term, ...loop.sources.user]) {
        const value = `${entry.from}:${entry.field}`;
        if (entry.type !== type || seen.has(value)) continue;
        seen.add(value);
        list.push({ value, label: `${place(entry.from)}: ${entry.label}`, entry, from: entry.from });
    }
    const rowType: Record<string, string[]> = {
        repeater: ['repeater'],
        related: ['post_select'],
        images: ['files', 'image'],
    };
    for (const sub of outerRepeaterSubs ?? []) {
        if (rowType[type].includes(sub.type)) {
            list.unshift({
                value: `row:${sub.id}`,
                label: sprintf(__('This row: %s', 'taw-core'), sub.label),
                entry: sub,
                from: 'row',
            });
        }
    }
    return list;
}

/**
 * A repeater loop's sub-fields: its field's, or, for `from: row`, the
 * enclosing repeater row's nested repeater. `chain` is outermost first and
 * ends with the loop asked about.
 */
export function repeaterSubs(config: Config, chain: LoopAttributes[], postType: string | undefined): SubField[] | null {
    const last = chain[chain.length - 1];
    if (!last || last.source.type !== 'repeater' || !last.source.field) return null;
    if (last.source.from === 'row') {
        const outer = repeaterSubs(config, chain.slice(0, -1), postType);
        return outer?.find((s) => s.id === last.source.field)?.subs ?? null;
    }
    const loop = config.loop;
    if (!loop) return null;
    const from = last.source.from ?? 'post';
    const pool =
        from === 'post'
            ? postType && loop.sources.post[postType]
                ? loop.sources.post[postType]
                : Object.values(loop.sources.post).flat()
            : loop.sources[from as 'option' | 'term' | 'user'];
    return pool.find((e) => e.type === 'repeater' && e.field === last.source.field)?.subs ?? null;
}

/** The post type a post item has (query or related), if it's known. */
export function itemPostType(
    config: Config,
    attributes: LoopAttributes,
    postType: string | undefined,
): string | undefined {
    const source = attributes.source;
    if (source.type === 'query') return source.postType ?? 'post';
    if (source.type === 'related') {
        const all = [...Object.values(config.loop?.sources.post ?? {}).flat(), ...(config.loop?.sources.option ?? [])];
        return all.find((e) => e.field === source.field && e.type === 'related')?.postType ?? postType;
    }
    return undefined;
}

function rowOption(group: string, name: string, label: string, isDate = false): ValueOption {
    return { key: `row:${name}`, group, label, name: `row.${name}`, args: { expr: `@row.${name}` }, isDate };
}

/**
 * The values an item offers, first in the popup: the Row group (by source)
 * and the Loop group (position). Post items also list their post type's
 * fields, which read the item's post. `chain` is outermost first.
 */
export function loopOptions(config: Config, chain: LoopAttributes[], postType: string | undefined): ValueOption[] {
    const last = chain[chain.length - 1];
    if (!last?.source.type) return [];
    const row = __('Row', 'taw-core');
    const options: ValueOption[] = [];

    switch (last.source.type) {
        case 'repeater':
            for (const sub of repeaterSubs(config, chain, postType) ?? []) {
                if (sub.type !== 'repeater') options.push(rowOption(row, sub.id, sub.label, sub.type === 'datepicker'));
            }
            break;
        case 'terms': {
            const props: [string, string][] = [
                ['name', __('Name', 'taw-core')],
                ['url', __('Link', 'taw-core')],
                ['description', __('Description', 'taw-core')],
                ['count', __('Post count', 'taw-core')],
                ['slug', __('Slug', 'taw-core')],
                ['id', __('ID', 'taw-core')],
            ];
            props.forEach(([name, label]) => options.push(rowOption(row, name, label)));
            for (const entry of config.fields.term) {
                if (entry.type === 'string' && !entry.args.sub)
                    options.push(rowOption(row, entry.args.field, entry.label));
            }
            break;
        }
        case 'images': {
            const image: FieldEntry = {
                label: __('Image', 'taw-core'),
                args: { field: '', row: 'image' } as BindingArgs,
                type: 'string',
                fieldType: 'image',
            };
            options.push({ ...rowOption(row, 'image', __('Image', 'taw-core')), entry: image });
            const props: [string, string][] = [
                ['url', __('URL', 'taw-core')],
                ['alt', __('Alt text', 'taw-core')],
                ['caption', __('Caption', 'taw-core')],
                ['title', __('Title', 'taw-core')],
                ['width', __('Width', 'taw-core')],
                ['height', __('Height', 'taw-core')],
            ];
            props.forEach(([name, label]) => options.push(rowOption(row, name, label)));
            break;
        }
        default: {
            // Posts: the item's own fields, when its type differs from the edited post's.
            const type = itemPostType(config, last, postType);
            if (type && type !== postType) {
                const item = sprintf(__('Item: %s', 'taw-core'), type);
                for (const option of valueOptions(config, type)) {
                    if (option.args.field && (option.args.from ?? 'post') === 'post')
                        options.push({ ...option, group: item, key: `item:${option.key}` });
                }
            }
        }
    }

    const loop = __('Loop', 'taw-core');
    const positions: [string, string][] = [
        ['index', __('Position (1, 2, 3…)', 'taw-core')],
        ['count', __('Number of items', 'taw-core')],
        ['first', __('Is first', 'taw-core')],
        ['last', __('Is last', 'taw-core')],
        ['even', __('Is even', 'taw-core')],
        ['odd', __('Is odd', 'taw-core')],
    ];
    for (const [name, label] of positions) {
        options.push({
            key: `loop:${name}`,
            group: loop,
            label,
            name: `loop.${name}`,
            args: { expr: `@loop.${name}` },
            isDate: false,
        });
    }
    return options;
}

export type Design = 'list' | 'cards' | 'blank';

type Template = [string, Record<string, unknown>?, Template[]?];

function bound(args: Record<string, unknown>, ...attributes: string[]): Record<string, unknown> {
    return {
        metadata: {
            bindings: Object.fromEntries(attributes.map((a) => [a, { source: 'taw/field', args }])),
        },
    };
}

/**
 * A starting design: the item's blocks and the loop's layout. Repeaters use
 * their first two sub-fields.
 */
export function design(
    type: SourceType,
    kind: Design,
    subs: SubField[],
): { item: Template[]; layout: LoopAttributes['layout'] } {
    const grid: LoopAttributes['layout'] = { type: 'grid', columns: 3 };
    if (kind === 'blank')
        return {
            item: [['core/paragraph', { placeholder: __('Add blocks for each item…', 'taw-core') }]],
            layout: { type: 'list' },
        };

    switch (type) {
        case 'repeater': {
            const [a, b] = subs.filter((s) => s.type !== 'repeater');
            const first = a?.id ?? 'name';
            if (kind === 'list') {
                const expr = b ? `@row.${first} · @row.${b.id}` : `@row.${first}`;
                return {
                    item: [['core/paragraph', bound({ expr }, 'content')]],
                    layout: { type: 'list' },
                };
            }
            const item: Template[] = [['core/heading', { level: 3, ...bound({ row: first }, 'content') }]];
            if (b) item.push(['core/paragraph', bound({ row: b.id }, 'content')]);
            return { item, layout: grid };
        }
        case 'terms':
            if (kind === 'list')
                return {
                    item: [['core/paragraph', bound({ expr: '@row.name (@row.count)' }, 'content')]],
                    layout: { type: 'list' },
                };
            return {
                item: [
                    ['core/heading', { level: 3, ...bound({ row: 'name' }, 'content') }],
                    ['core/paragraph', bound({ row: 'description' }, 'content')],
                ],
                layout: grid,
            };
        case 'images':
            if (kind === 'list')
                return { item: [['core/image', bound({ row: 'image' }, 'id', 'url', 'alt')]], layout: grid };
            return {
                item: [
                    ['core/image', bound({ row: 'image' }, 'id', 'url', 'alt')],
                    ['core/paragraph', { fontSize: 'small', ...bound({ row: 'caption' }, 'content') }],
                ],
                layout: grid,
            };
        default:
            if (kind === 'list')
                return { item: [['core/post-title', { level: 3, isLink: true }]], layout: { type: 'list' } };
            return {
                item: [
                    ['core/post-featured-image', { isLink: true, aspectRatio: '4/3' }],
                    ['core/post-title', { level: 3, isLink: true }],
                    ['core/post-excerpt', { excerptLength: 20 }],
                ],
                layout: grid,
            };
    }
}

/** The item list's class and inline style, as the server renders them. */
export function listClass(layout: LoopAttributes['layout'] | undefined): string {
    return layout?.type === 'grid' ? 'taw-loop__items is-grid' : 'taw-loop__items';
}

export function listStyle(layout: LoopAttributes['layout'] | undefined): Record<string, string> {
    const style: Record<string, string> = {};
    if (layout?.type === 'grid') style['--taw-loop-columns'] = String(Math.max(1, Math.min(6, layout.columns ?? 3)));
    if (layout?.gap && /^\d+(\.\d+)?(px|rem|em|%)$/.test(layout.gap)) style['--taw-loop-gap'] = layout.gap;
    return style;
}

/**
 * An item's server HTML split into its `<li>`'s classes and its content: the
 * editor renders the `<li>` itself, with the same classes (layout included).
 */
export function splitItem(html: string): { className: string; html: string } {
    const match = /^\s*<li\b([^>]*)>([\s\S]*)<\/li>\s*$/.exec(html);
    if (!match) return { className: 'wp-block-taw-loop-item', html };
    const className = /\bclass="([^"]*)"/.exec(match[1])?.[1] ?? 'wp-block-taw-loop-item';
    return { className, html: match[2] };
}

/**
 * Inline dynamic tags and expressions in the editor (taw/core ADR-0011,
 * ADR-0012). Pure logic: the WordPress glue is in popup.tsx and chips.tsx.
 *
 * A chip is an atomic inline element, like core's footnotes:
 *
 *   <span class="taw-tag" data-taw-tag='{"expr":"Published on @post.date.format(\'Y\')"}'>Published on 2026</span>
 *
 * Its text is the value when it was inserted (or refreshed); the front end
 * always renders the live value (Bindings\InlineTags).
 */
import { __ } from '@wordpress/i18n';
import { FUNCTIONS } from './expression';
import type { BindingArgs, Config, FieldEntry } from './logic';

export const FORMAT = 'taw/tag';
export const CLASS_NAME = 'taw-tag';
export const ATTRIBUTE = 'data-taw-tag';

/** What data-taw-tag holds: an expression, a property or a field (the taw/field args), plus a fallback. */
export interface TagArgs {
    expr?: string;
    tag?: string;
    field?: string;
    from?: BindingArgs['from'];
    sub?: string;
    format?: string;
    fallback?: string;
}

/** One value the popup offers. */
export interface ValueOption {
    /** Stable id: what it reads. */
    key: string;
    /** Heading it's listed under: the fieldset title, or Post / Site / Options / Term / Author. */
    group: string;
    label: string;
    /** How an expression names it, without the `@`: `book_year`, `post.title`, `option.company_phone`. */
    name: string;
    /** A chip's args for this value alone. */
    args: TagArgs;
    isDate: boolean;
    /** The field entry, for "Use as block text" with the taw/field plan (images, links…). */
    entry?: FieldEntry;
}

function properties(): ValueOption[] {
    const post = __('Post', 'taw-core');
    const site = __('Site', 'taw-core');
    const list: [string, string, string, boolean][] = [
        [post, 'post.title', __('Title', 'taw-core'), false],
        [post, 'post.date', __('Date', 'taw-core'), true],
        [post, 'post.modified', __('Modified date', 'taw-core'), true],
        [post, 'post.author', __('Author', 'taw-core'), false],
        [post, 'post.excerpt', __('Excerpt', 'taw-core'), false],
        [post, 'post.url', __('URL', 'taw-core'), false],
        [post, 'post.id', __('ID', 'taw-core'), false],
        [post, 'post.type', __('Type', 'taw-core'), false],
        [site, 'site.name', __('Site title', 'taw-core'), false],
        [site, 'site.tagline', __('Tagline', 'taw-core'), false],
        [site, 'site.url', __('Site URL', 'taw-core'), false],
        [site, 'site.year', __('Current year', 'taw-core'), false],
    ];
    return list.map(([group, tag, label, isDate]) => ({ key: tag, group, label, name: tag, args: { tag }, isDate }));
}

const NAMESPACE: Record<string, string> = { post: '', option: 'option.', term: 'term.', user: 'author.' };

function fallbackGroup(from: string): string {
    switch (from) {
        case 'option':
            return __('Options', 'taw-core');
        case 'term':
            return __('Term', 'taw-core');
        case 'user':
            return __('Author', 'taw-core');
        default:
            return __('Post fields', 'taw-core');
    }
}

/** A field entry as an option (text entries only: "(image ID)" entries are for core/image's id). */
function fieldOption(entry: FieldEntry): ValueOption | null {
    if (entry.type !== 'string') return null;
    const from = entry.args.from ?? 'post';
    const args: TagArgs = { field: entry.args.field, from };
    if (entry.args.sub) args.sub = entry.args.sub;
    // A group's sub-field is stored and read as `{group}_{sub}`.
    const id = entry.args.sub ? `${entry.args.field}_${entry.args.sub}` : entry.args.field;
    return {
        key: tagKey(args),
        group: entry.fieldset?.trim() ? entry.fieldset : fallbackGroup(from),
        label: entry.label,
        name: `${NAMESPACE[from] ?? ''}${id}`,
        args,
        isDate: entry.fieldType === 'datepicker',
        entry,
    };
}

/** Everything the popup offers for a post type (or, in a template without one, every post type's fields). */
export function valueOptions(config: Config, postType: string | undefined): ValueOption[] {
    const postEntries =
        postType && config.fields.post[postType]
            ? config.fields.post[postType]
            : Object.values(config.fields.post).flat();
    const seen = new Set<string>();
    const fields = [...postEntries, ...config.fields.option, ...config.fields.term, ...config.fields.user]
        .map(fieldOption)
        .filter((option): option is ValueOption => {
            if (!option || seen.has(option.key)) return false;
            seen.add(option.key);
            return true;
        });
    return [...fields, ...properties()];
}

/** Options grouped by heading, in first-seen order, filtered by a search. */
export function groupOptions(options: ValueOption[], search: string): [string, ValueOption[]][] {
    const needle = search.trim().toLowerCase();
    const groups = new Map<string, ValueOption[]>();
    for (const option of options) {
        const haystack = `${option.group} ${option.label} ${option.name}`.toLowerCase();
        if (needle && !haystack.includes(needle)) continue;
        groups.set(option.group, [...(groups.get(option.group) ?? []), option]);
    }
    return [...groups];
}

/** Name suggestions for `@prefix` in the expression editor. */
export function nameSuggestions(options: ValueOption[], prefix: string, limit = 8): ValueOption[] {
    const needle = prefix.toLowerCase();
    const starts = options.filter((o) => o.name.toLowerCase().startsWith(needle));
    const contains = options.filter(
        (o) => !starts.includes(o) && (o.name.toLowerCase().includes(needle) || o.label.toLowerCase().includes(needle)),
    );
    return [...starts, ...contains].slice(0, limit);
}

/** Function suggestions after `name.`, as the text they insert. */
export function functionSuggestions(prefix: string): { fn: string; insert: string }[] {
    return Object.entries(FUNCTIONS)
        .filter(([fn]) => fn.startsWith(prefix))
        .map(([fn, kinds]) => ({
            fn,
            insert:
                kinds.length === 0
                    ? `${fn}()`
                    : kinds[0] === 'int'
                      ? `${fn}(20)`
                      : fn === 'format'
                        ? `${fn}('F j, Y')`
                        : `${fn}('')`,
        }));
}

/** The identity of a tag: what it reads, without its formatting. */
export function tagKey(args: TagArgs): string {
    if (args.expr !== undefined) return `expr:${args.expr}`;
    return args.tag ?? `${args.from ?? 'post'}:${args.field ?? ''}${args.sub ? `.${args.sub}` : ''}`;
}

/** data-taw-tag's JSON, with a stable key order and no empty options. */
export function serializeTag(args: TagArgs): string {
    let out: TagArgs;
    if (args.expr !== undefined) out = { expr: args.expr };
    else if (args.tag) out = { tag: args.tag };
    else {
        out = { field: args.field, from: args.from ?? 'post' };
        if (args.sub) out.sub = args.sub;
    }
    if (args.expr === undefined && args.format?.trim()) out.format = args.format.trim();
    if (args.fallback?.trim()) out.fallback = args.fallback;
    return JSON.stringify(out);
}

/** data-taw-tag's JSON back to args, or null when it isn't a tag. */
export function parseTag(json: unknown): TagArgs | null {
    if (typeof json !== 'string') return null;
    try {
        const value: unknown = JSON.parse(json);
        if (!value || typeof value !== 'object') return null;
        const args = value as TagArgs;
        return typeof args.expr === 'string' || typeof args.tag === 'string' || typeof args.field === 'string'
            ? args
            : null;
    } catch {
        return null;
    }
}

/** A chip's args as an expression, to edit it in the Expression tab. */
export function toExpression(args: TagArgs, options: ValueOption[]): string {
    if (args.expr !== undefined) return args.expr;
    const option = options.find((o) => o.key === tagKey(args));
    const name = option?.name ?? args.tag ?? args.field ?? '';
    let expression = `@${name}`;
    if (args.format) expression += `.format(${quote(args.format)})`;
    if (args.fallback) expression += `.default(${quote(args.fallback)})`;
    return expression;
}

function quote(text: string): string {
    return `'${text.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

/** The text a chip stores: the value, else a bracketed label so an empty chip still shows in the editor. */
export function storedText(value: unknown, label: string): string {
    return typeof value === 'string' && value.trim() !== '' ? value : `[${label}]`;
}

export function escapeHtml(text: string): string {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** The rich-text object for a chip (as core footnotes insert theirs). */
export function chipObject(
    args: TagArgs,
    text: string,
): { type: string; attributes: Record<string, string>; innerHTML: string } {
    return { type: FORMAT, attributes: { [ATTRIBUTE]: serializeTag(args) }, innerHTML: escapeHtml(text) };
}

/** Error codes (Parser) → messages. */
export function errorMessage(code: string): string {
    switch (code) {
        case 'unknown_function':
            return __('Unknown function. Use format, upper, lower, default or truncate.', 'taw-core');
        case 'wrong_arguments':
            return __('Wrong arguments for this function.', 'taw-core');
        case 'bad_arguments':
            return __('Unclosed parenthesis or quote.', 'taw-core');
        case 'unknown_name':
            return __('Unknown value name.', 'taw-core');
        case 'too_long':
            return __('The expression is too long (500 characters at most).', 'taw-core');
        case 'too_many_tokens':
            return __('Too many values (20 at most).', 'taw-core');
        default:
            return code;
    }
}

/**
 * Dynamic block settings (ADR-0016): `metadata.tawSettings` and how an
 * expression's result is cleaned. Mirrors PHP Settings\Normalizer; both pass
 * tests/fixtures/block-settings.json. The server does the real cleaning (and
 * evaluation); this lets the editor read, write and check settings as you type.
 */

export const KEY = 'tawSettings';

export const COLOR_SETTINGS = ['color', 'background', 'border'] as const;
export type ColorSetting = (typeof COLOR_SETTINGS)[number];

export const ATTRIBUTES = ['id', 'title', 'aria-label', 'aria-description'];

export const MAX_CLASSES = 20;
export const MAX_ATTRIBUTES = 10;
export const MAX_VALUE_LENGTH = 500;

export interface Settings {
    classes?: string;
    color?: string;
    background?: string;
    border?: string;
    attributes?: Record<string, string>;
}

export type Dropped = 'error' | 'empty' | 'not_a_color' | 'palette_only' | 'unknown_setting';

type Metadata = { [key: string]: unknown };

/** CSS named colors (CSS Color 4), lowercase. */
// prettier-ignore
const NAMED = new Set([
    'aliceblue', 'antiquewhite', 'aqua', 'aquamarine', 'azure', 'beige', 'bisque', 'black', 'blanchedalmond',
    'blue', 'blueviolet', 'brown', 'burlywood', 'cadetblue', 'chartreuse', 'chocolate', 'coral',
    'cornflowerblue', 'cornsilk', 'crimson', 'cyan', 'darkblue', 'darkcyan', 'darkgoldenrod', 'darkgray',
    'darkgreen', 'darkgrey', 'darkkhaki', 'darkmagenta', 'darkolivegreen', 'darkorange', 'darkorchid',
    'darkred', 'darksalmon', 'darkseagreen', 'darkslateblue', 'darkslategray', 'darkslategrey',
    'darkturquoise', 'darkviolet', 'deeppink', 'deepskyblue', 'dimgray', 'dimgrey', 'dodgerblue', 'firebrick',
    'floralwhite', 'forestgreen', 'fuchsia', 'gainsboro', 'ghostwhite', 'gold', 'goldenrod', 'gray', 'green',
    'greenyellow', 'grey', 'honeydew', 'hotpink', 'indianred', 'indigo', 'ivory', 'khaki', 'lavender',
    'lavenderblush', 'lawngreen', 'lemonchiffon', 'lightblue', 'lightcoral', 'lightcyan',
    'lightgoldenrodyellow', 'lightgray', 'lightgreen', 'lightgrey', 'lightpink', 'lightsalmon',
    'lightseagreen', 'lightskyblue', 'lightslategray', 'lightslategrey', 'lightsteelblue', 'lightyellow',
    'lime', 'limegreen', 'linen', 'magenta', 'maroon', 'mediumaquamarine', 'mediumblue', 'mediumorchid',
    'mediumpurple', 'mediumseagreen', 'mediumslateblue', 'mediumspringgreen', 'mediumturquoise',
    'mediumvioletred', 'midnightblue', 'mintcream', 'mistyrose', 'moccasin', 'navajowhite', 'navy', 'oldlace',
    'olive', 'olivedrab', 'orange', 'orangered', 'orchid', 'palegoldenrod', 'palegreen', 'paleturquoise',
    'palevioletred', 'papayawhip', 'peachpuff', 'peru', 'pink', 'plum', 'powderblue', 'purple',
    'rebeccapurple', 'red', 'rosybrown', 'royalblue', 'saddlebrown', 'salmon', 'sandybrown', 'seagreen',
    'seashell', 'sienna', 'silver', 'skyblue', 'slateblue', 'slategray', 'slategrey', 'snow', 'springgreen',
    'steelblue', 'tan', 'teal', 'thistle', 'tomato', 'turquoise', 'violet', 'wheat', 'white', 'whitesmoke',
    'yellow', 'yellowgreen', 'transparent', 'currentcolor',
]);

const NUMBER = '[-+]?(?:\\d+\\.?\\d*|\\.\\d+)(?:%|deg)?';
const FUNCTIONAL = new RegExp(`^(?:rgba?|hsla?)\\(\\s*${NUMBER}(?:\\s*[,/]?\\s*${NUMBER}){2,3}\\s*\\)$`);

const isExpression = (value: unknown): value is string => typeof value === 'string' && value.trim() !== '';

/** The settings a block may carry: expression strings only, refused attribute names dropped. */
export function readSettings(raw: unknown): Settings {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return {};
    const input = raw as Record<string, unknown>;
    const out: Settings = {};
    for (const key of ['classes', ...COLOR_SETTINGS] as const) {
        const value = input[key];
        if (isExpression(value)) out[key] = value;
    }
    const attributes: Record<string, string> = {};
    const given = input.attributes;
    if (given && typeof given === 'object' && !Array.isArray(given)) {
        for (const [name, expression] of Object.entries(given as Record<string, unknown>)) {
            if (Object.keys(attributes).length >= MAX_ATTRIBUTES) break;
            if (isAttribute(name) && isExpression(expression)) attributes[name] = expression;
        }
    }
    if (Object.keys(attributes).length > 0) out.attributes = attributes;
    return out;
}

/** Whether a block has any setting. */
export function hasSettings(settings: Settings): boolean {
    return Object.keys(settings).length > 0;
}

/**
 * The block's metadata with one setting (classes, a color, or an attribute
 * name) set to an expression; an empty one removes it, and no settings
 * removes the key.
 */
export function withSetting(metadata: Metadata, setting: string, expression: string): Metadata {
    const current = readSettings(metadata[KEY]);
    const next: Settings = { ...current, attributes: { ...(current.attributes ?? {}) } };
    const keep = expression.trim() !== '';
    if (setting === 'classes' || (COLOR_SETTINGS as readonly string[]).includes(setting)) {
        const key = setting as 'classes' | ColorSetting;
        if (keep) next[key] = expression;
        else delete next[key];
    } else if (isAttribute(setting)) {
        if (keep) next.attributes![setting] = expression;
        else delete next.attributes![setting];
    }
    if (Object.keys(next.attributes ?? {}).length === 0) delete next.attributes;
    const rest = { ...metadata };
    delete rest[KEY];
    return hasSettings(next) ? { ...rest, [KEY]: next } : rest;
}

function className(token: string): string {
    return token.replace(/%[a-fA-F0-9][a-fA-F0-9]/g, '').replace(/[^A-Za-z0-9_-]/g, '');
}

/** Space-separated class names, cleaned like sanitize_html_class(), no duplicates, at most MAX_CLASSES. */
export function cleanClasses(value: string): string[] {
    const out: string[] = [];
    for (const token of value.trim().split(/\s+/)) {
        const clean = className(token);
        if (clean !== '' && !out.includes(clean)) out.push(clean);
        if (out.length === MAX_CLASSES) break;
    }
    return out;
}

function isCssColor(color: string): boolean {
    return /^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/.test(color) || NAMED.has(color) || FUNCTIONAL.test(color);
}

/** A palette slug → its CSS value, else (custom colors allowed) a CSS color, lowercased. */
export function cleanColor(
    value: string,
    palette: Record<string, string>,
    custom = true,
): { value: string | null; dropped: Dropped | null } {
    const color = value.trim().toLowerCase();
    if (color === '') return { value: null, dropped: 'empty' };
    if (Object.prototype.hasOwnProperty.call(palette, color)) return { value: palette[color], dropped: null };
    if (!isCssColor(color)) return { value: null, dropped: 'not_a_color' };
    return custom ? { value: color, dropped: null } : { value: null, dropped: 'palette_only' };
}

/** An attribute an expression may set: id, title, aria-label, aria-description or data-*. */
export function isAttribute(name: string): boolean {
    return ATTRIBUTES.includes(name) || /^data-[a-z0-9]+(?:-[a-z0-9]+)*$/.test(name);
}

/** An attribute's value: `id` is one class-like token, the others plain text. */
export function cleanAttribute(name: string, value: string): { value: string | null; dropped: Dropped | null } {
    let clean: string;
    if (name === 'id') {
        clean = className(value.trim().split(/\s+/)[0] ?? '');
    } else {
        clean = value
            .replace(/<[^>]*>/g, '')
            .replace(/\s+/g, ' ')
            .trim();
        clean = Array.from(clean).slice(0, MAX_VALUE_LENGTH).join('');
    }
    return clean === '' ? { value: null, dropped: 'empty' } : { value: clean, dropped: null };
}

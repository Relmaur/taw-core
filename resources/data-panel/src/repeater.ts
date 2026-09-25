import type { FieldDescriptor } from './types';

export type Row = Record<string, unknown>;

/** Rows as the `taw_<id>` REST field decodes them (anything else is dropped). */
export function asRows(value: unknown): Row[] {
    return Array.isArray(value)
        ? value.filter((row): row is Row => typeof row === 'object' && row !== null && !Array.isArray(row))
        : [];
}

/** A new row: each sub-field's `default`, when it has one. */
export function newRow(subs: FieldDescriptor[]): Row {
    const row: Row = {};
    for (const sub of subs) {
        if (sub.default !== undefined && sub.default !== null) row[sub.id] = sub.default;
    }
    return row;
}

const TITLE_TYPES = ['text', 'textarea', 'url', 'select', 'number', 'datepicker'];

/** A row's summary: the first text-like sub-field with a value (select options by label). */
export function rowSummary(row: Row, subs: FieldDescriptor[]): string {
    for (const sub of subs) {
        if (!TITLE_TYPES.includes(sub.type)) continue;
        const raw = row[sub.id];
        if (raw === undefined || raw === null || String(raw).trim() === '') continue;
        let text = String(raw);
        if (sub.type === 'select' && sub.options && !Array.isArray(sub.options)) {
            text = sub.options[text] ?? text;
        }
        text = text.replace(/\s+/g, ' ').trim();
        return text.length > 48 ? `${text.slice(0, 47)}…` : text;
    }
    return '';
}

/** A copy of `list` with the item at `from` moved to `to`. */
export function move<T>(list: T[], from: number, to: number): T[] {
    if (to < 0 || to >= list.length || from === to) return list;
    const next = [...list];
    next.splice(to, 0, ...next.splice(from, 1));
    return next;
}

let counter = 0;

/** A key for a new row (React needs one that moves with the row). */
export function rowKey(): string {
    counter += 1;
    return `r${counter}`;
}

/**
 * Keys for the current rows: the known ones when the count still matches
 * (the panel changed the rows and updated the keys), fresh ones otherwise
 * (the rows changed elsewhere, such as undo).
 */
export function syncKeys(keys: string[], count: number): string[] {
    return keys.length === count ? keys : Array.from({ length: count }, () => rowKey());
}

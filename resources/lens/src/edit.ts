/**
 * Editing text in place (component 05, screens 08–10): what can be edited on the page, and what an edit becomes.
 *
 * - A **text field** (text, long text) is edited as plain text and saved straight away (`POST taw/v1/lens/field`).
 * - A **rich-text field** is edited with only the formats the field allows, and saved in the classic editor's
 *   format (rich.ts).
 * - **Template text** is never saved by Lens: the edit becomes a message to Claude, who shows the diff to apply.
 *
 * Lens only offers it when the page shows the value exactly as stored, in an element that holds nothing else:
 * anything it can't edit without loss goes to wp-admin (or to Claude) instead, with the reason.
 */
import type { Target } from './describe';
import { __ } from './i18n';
import type { Manifest, Read } from './manifest';
import { messageChips, selectionsPayload } from './payload';
import { readHtml, readStored, sameBlocks, type Format, type RichBlock } from './rich';
import {
    normalize,
    sourceKey,
    textOf,
    type Attribution,
    type FieldSource,
    type PageMap,
    type TemplateSource,
} from './sourcemap';

export type EditPlan =
    | { kind: 'field'; mode: 'text'; source: FieldSource; el: Element; original: string }
    | {
          kind: 'field';
          mode: 'rich';
          source: FieldSource;
          el: Element;
          original: string;
          formats: Format[];
          blocks: RichBlock[];
      }
    | { kind: 'template'; source: TemplateSource; el: Element; original: string };

/** Editing in place is offered (a plan), not possible here (why), or not something Lens edits (null). */
export type Verdict = { plan: EditPlan } | { reason: string } | null;

const ROW = /^(.+)\[(\d+)\]\.(.+)$/;
const FORMATS: readonly Format[] = ['bold', 'italic', 'link', 'lists'];

/** What the source map says about editing a field or a repeater row's sub-field. */
export function editHints(read: Read, path: string): { edit?: 'text' | 'rich'; formats?: Format[]; type: string } {
    const row = ROW.exec(path);
    const hints = row ? read.subs?.[row[3]] : read;
    const formats = (hints?.formats ?? []).filter((f): f is Format => FORMATS.includes(f as Format));

    return {
        edit: hints?.edit === 'text' || hints?.edit === 'rich' ? hints.edit : undefined,
        formats,
        type: hints?.type ?? read.type,
    };
}

/** The value the page shows for a field or a repeater row's sub-field; null when it isn't text. */
export function shownValue(read: Read, path: string): string | null {
    let value: unknown = read.value;
    const row = ROW.exec(path);
    if (row) {
        try {
            const rows: unknown = typeof value === 'string' ? JSON.parse(value) : value;
            const list = Array.isArray(rows) ? rows.filter((r) => typeof r === 'object' && r !== null) : [];
            value = (list[Number(row[2])] as Record<string, unknown> | undefined)?.[row[3]];
        } catch {
            return null;
        }
    }

    return typeof value === 'string' || typeof value === 'number' ? String(value) : null;
}

/** Editing replaces an element's text, so it must hold nothing else. */
const onlyText = (el: Element): boolean =>
    el.childNodes.length > 0 &&
    [...el.childNodes].every((node) => node.nodeType === Node.TEXT_NODE || node.nodeType === Node.COMMENT_NODE);

const sameField = (a: Attribution, source: FieldSource): a is Extract<Attribution, { kind: 'field' }> =>
    a.kind === 'field' && sourceKey(a.source) === sourceKey(source);

const NOT_FAITHFUL = (): string =>
    __(
        'The page shows this field differently from how it’s stored, so Lens can’t edit it here. Open it in wp-admin.',
        'taw-core',
    );

function richPlan(map: PageMap, el: Element, source: FieldSource, value: string, formats: Format[]): Verdict {
    const stored = readStored(value, formats, el.ownerDocument);
    if (stored.lossy) {
        return {
            reason: __(
                'This field has formatting Lens can’t edit on the page, such as images or headings. Open it in wp-admin.',
                'taw-core',
            ),
        };
    }
    // The outermost element that shows the whole value and nothing else: Enter then makes a sibling paragraph.
    let found: Element | null = null;
    for (let at: Element | null = el; at && !['BODY', 'HTML'].includes(at.tagName); at = at.parentElement) {
        const a = map.attribute(at);
        if (!sameField(a, source) || a.source.part) {
            if (found) {
                break;
            }
            continue;
        }
        const shown = readHtml(at.innerHTML, formats, at.ownerDocument);
        if (!shown.lossy && sameBlocks(shown.blocks, stored.blocks)) {
            found = at;
        } else if (found) {
            break;
        }
    }

    return found
        ? { plan: { kind: 'field', mode: 'rich', source, el: found, original: value, formats, blocks: stored.blocks } }
        : { reason: NOT_FAITHFUL() };
}

/** Whether and how the selection can be edited on the page. */
export function planEdit(map: PageMap, target: Target): Verdict {
    const a = target.attribution;
    const el = target.elements[0];
    if (!el) {
        return null;
    }

    if (a.kind === 'template') {
        if (!onlyText(el) || textOf(el) !== a.source.text) {
            return {
                reason: __('This text shares its element with other markup. Ask Claude to change it.', 'taw-core'),
            };
        }
        return { plan: { kind: 'template', source: a.source, el, original: textOf(el) } };
    }
    if (a.kind !== 'field') {
        return null;
    }

    const source = a.source;
    const read = source.read;
    // WordPress data (titles, menu items, the site title) is changed in wp-admin or by Claude.
    if (read.store === 'wp' || read.store === 'menu' || read.type === 'wp') {
        return null;
    }
    const hints = editHints(read, source.path);
    if (!hints.edit) {
        return read.type === 'image' || hints.type === 'image'
            ? null
            : { reason: __('This kind of field can’t be edited on the page. Open it in wp-admin.', 'taw-core') };
    }
    const value = shownValue(read, source.path);
    if (value === null) {
        return { reason: NOT_FAITHFUL() };
    }
    const whole: FieldSource = source.part ? { ...source, part: undefined } : source;
    if (hints.edit === 'rich') {
        return richPlan(map, el, whole, value, hints.formats ?? []);
    }

    if (source.part) {
        return { reason: NOT_FAITHFUL() };
    }
    if (/[\r\n]/.test(value.trim())) {
        return {
            reason: __(
                'This text has line breaks, which editing on the page would lose. Open it in wp-admin.',
                'taw-core',
            ),
        };
    }
    if (!onlyText(el) || normalize(value) !== textOf(el)) {
        return { reason: NOT_FAITHFUL() };
    }

    return { plan: { kind: 'field', mode: 'text', source, el, original: value } };
}

/** The body of `POST taw/v1/lens/field` (TAW\Core\Lens\FieldWriter). `value: null` puts back "nothing stored". */
export interface FieldRequest {
    store: Read['store'];
    object: number | string;
    key: string;
    row?: number;
    sub?: string;
    value: string | null;
    expect: string | null;
}

export function fieldRequest(source: FieldSource, value: string | null, expect: string | null): FieldRequest {
    const row = ROW.exec(source.path);

    return {
        store: source.read.store,
        object: source.read.object,
        key: source.read.key,
        ...(row ? { row: Number(row[2]), sub: row[3] } : {}),
        value,
        expect,
    };
}

/** Template text changed on the page, as a message to Claude: the change, then where it is. */
export function templateMessage(
    manifest: Manifest,
    target: Target,
    source: TemplateSource,
    after: string,
    page: { url: string; title: string },
): { text: string; context: string; chips: string[]; from: 'edit' } {
    const before = source.text;
    const quote = (text: string) => text.replace(/\n/g, ' ');

    return {
        text: __('Change "%1$s" to "%2$s".', 'taw-core').replace('%1$s', before).replace('%2$s', after),
        context: [
            `<lens-edit file="${source.file}" line="${source.line}">`,
            `Before: ${quote(before)}`,
            `After: ${quote(after)}`,
            '</lens-edit>',
            '',
            selectionsPayload(manifest, [target], page),
        ].join('\n'),
        chips: messageChips(manifest, [target]),
        from: 'edit',
    };
}

/**
 * What Claude receives about the selections: the `<lens-selections>` block the bridge appends to a message
 * (umbrella ADR-0006 § 7). Built from the same source map as the inspect card and the structure tree, so the
 * three never disagree; resources/lens-bridge/agent-prompt.md tells Claude how to read it.
 *
 * Plain English on purpose, not translated: it is read by Claude, and by a developer checking what was sent.
 */
import { chipLabel, isWordPress, nodeName, shortName, truncate, type Target } from './describe';
import { __ } from './i18n';
import { chainOf, type Manifest, type Read } from './manifest';
import type { FieldSource, Source, TemplateSource } from './sourcemap';

const VALUE_MAX = 400;

const quote = (text: string, max = VALUE_MAX): string =>
    JSON.stringify(truncate(text.replace(/\s+/g, ' ').trim(), max));

/** A short CSS selector for an element: its id, or tag + classes up from the nearest ancestor with an id. */
export function selectorOf(el: Element): string {
    const steps: string[] = [];
    for (let node: Element | null = el; node && steps.length < 5; node = node.parentElement) {
        const tag = node.tagName.toLowerCase();
        if (tag === 'html' || tag === 'body') {
            break;
        }
        if (node.id && /^[A-Za-z][\w-]*$/.test(node.id)) {
            steps.unshift(`#${node.id}`);
            break;
        }
        const classes = [...node.classList].filter((c) => /^[A-Za-z_-][\w-]*$/.test(c)).slice(0, 2);
        let step = tag + classes.map((c) => `.${c}`).join('');
        const parent = node.parentElement;
        if (parent) {
            const same = [...parent.children].filter((c) => c.tagName === node!.tagName);
            if (same.length > 1) {
                step += `:nth-of-type(${same.indexOf(node) + 1})`;
            }
        }
        steps.unshift(step);
    }

    return steps.join(' > ');
}

/** Where a value is stored, as the agent addresses it (`taw fields:set <where> …`). */
function storedOn(read: Read): string {
    switch (read.store) {
        case 'post':
            return `post ${read.object}`;
        case 'term':
            return `term ${read.object}`;
        case 'user':
            return `user ${read.object}`;
        case 'option':
            return `options page ${read.object} (taw fields:set options …)`;
        case 'menu':
            return `menu ${read.object}, ${read.key}`;
        default:
            return 'WordPress settings';
    }
}

function renderedIn(manifest: Manifest, nodeId: number): string {
    const chain = chainOf(manifest, nodeId);
    const node = chain[0];
    const where = chain.slice().reverse().map(nodeName).filter(Boolean).join(' › ');
    if (!node) {
        return where;
    }
    const kind =
        node.kind === 'block' ? `block ${node.name}` : node.kind === 'part' ? 'template part' : 'page template';

    return `${where} (${kind}${node.template ? `, ${node.template}` : ''})`;
}

function fieldLines(manifest: Manifest, source: FieldSource): string[] {
    const { read } = source;
    const lines: string[] = [];
    if (isWordPress(read)) {
        lines.push(`WordPress data: ${read.label}${read.key === 'post_title' ? ` of post ${read.object}` : ''}`);
        if (read.store === 'menu') {
            lines.push(
                `Menu item ${read.key} in menu ${read.object}${read.target ? `, linking to post ${read.target}` : ''}`,
            );
        }
    } else {
        const row = /^(.+?)\[(\d+)\]\.(.+)$/.exec(source.path);
        lines.push(
            row
                ? `Field ${row[1]}, row ${Number(row[2]) + 1}, sub-field ${row[3]} (${source.label}; repeater)`
                : `Field ${source.path} (${source.label}, ${read.type})`,
        );
        lines.push(`Stored on: ${storedOn(read)}`);
    }
    const value = typeof read.value === 'string' ? read.value : JSON.stringify(read.value ?? '');
    lines.push(`Value: ${quote(value)}${source.part ? ' (the element shows part of it)' : ''}`);
    if (read.default) {
        lines.push('Nothing is stored yet: the page shows the field’s default.');
    }
    lines.push(`Rendered in: ${renderedIn(manifest, source.node)}`);
    if (read.admin) {
        lines.push(`By hand: ${read.admin}`);
    }

    return lines;
}

function templateLines(manifest: Manifest, source: TemplateSource): string[] {
    const how =
        source.how === 'i18n'
            ? ', translatable (inside a gettext call such as esc_html_e)'
            : source.how === 'literal'
              ? ', a PHP string'
              : '';
    return [
        `Template text ${quote(source.text, 200)}`,
        `File: ${source.file} line ${source.line}${how}`,
        `Rendered in: ${renderedIn(manifest, source.node)}`,
    ];
}

const sourceLines = (manifest: Manifest, source: Source): string[] =>
    source.kind === 'field' ? fieldLines(manifest, source) : templateLines(manifest, source);

function describeTarget(manifest: Manifest, target: Target): string[] {
    const a = target.attribution;
    switch (a.kind) {
        case 'field':
        case 'template':
            return sourceLines(manifest, a.source);
        case 'ambiguous':
            return [
                `Ambiguous: the text ${quote(a.text, 200)} comes from ${a.sources.length} places. Don't pick one: ask which.`,
                ...a.sources.flatMap((s, i) => [
                    `Candidate ${i + 1}:`,
                    ...sourceLines(manifest, s).map((l) => `  ${l}`),
                ]),
            ];
        case 'mixed':
            return [
                `Mixed: template text around values. The text: ${quote(a.text, 200)}`,
                ...a.sources.flatMap((s, i) => [`Piece ${i + 1}:`, ...sourceLines(manifest, s).map((l) => `  ${l}`)]),
            ];
        case 'block': {
            const node = manifest.nodes.get(a.node);
            if (!node) {
                return ['A block'];
            }
            const kind =
                node.kind === 'block'
                    ? `Block ${node.name} (${nodeName(node)})`
                    : node.kind === 'part'
                      ? `Template part ${node.name}`
                      : `The page (${node.template ?? node.name})`;
            const lines = [kind, `Rendered in: ${renderedIn(manifest, a.node)}`];
            if (node.files?.length) {
                lines.push(`Files: ${node.files.join(', ')}`);
            } else if (node.template) {
                lines.push(`File: ${node.template}`);
            }
            const fields = node.reads.filter((r) => !isWordPress(r)).map((r) => `${r.key} (${r.label})`);
            if (fields.length) {
                lines.push(`Its fields: ${fields.join(', ')}`);
            }
            return lines;
        }
        default:
            return [
                `An element whose text TAW didn't render from a field or template text${a.text ? `: ${quote(a.text, 200)}` : ''}. JavaScript may add it, or it is computed.`,
                `Rendered in: ${renderedIn(manifest, a.node)}`,
            ];
    }
}

/** The `<lens-selections>` block for a message, or '' with nothing selected. */
export function selectionsPayload(
    manifest: Manifest | null,
    targets: Target[],
    page: { url: string; title: string },
): string {
    if (!manifest || targets.length === 0) {
        return '';
    }
    const blocks = targets.map((target, i) => {
        const el = target.elements[0];
        const lines = describeTarget(manifest, target);
        if (el) {
            lines.push(`Element: <${el.tagName.toLowerCase()}> at \`${selectorOf(el)}\``);
        }
        return [`${i + 1}. ${lines[0]}`, ...lines.slice(1).map((l) => `   ${l}`)].join('\n');
    });

    return [
        `<lens-selections page=${JSON.stringify(page.title)} url=${JSON.stringify(page.url)}>`,
        'What the developer selected on the page, from TAW’s source map:',
        ...blocks,
        '</lens-selections>',
    ].join('\n');
}

/** The chips a sent message shows: "hero_title · field", "Hero · block". Unlike the payload, these are UI: translated. */
export function messageChips(manifest: Manifest | null, targets: Target[]): string[] {
    if (!manifest) {
        return [];
    }

    return targets.map((target) => {
        const a = target.attribution;
        const node = a.kind === 'block' ? manifest.nodes.get(a.node) : undefined;
        const kind =
            a.kind === 'field'
                ? isWordPress(a.source.read)
                    ? __('WordPress', 'taw-core')
                    : __('field', 'taw-core')
                : a.kind === 'template'
                  ? __('template text', 'taw-core')
                  : a.kind === 'block'
                    ? node?.kind === 'page'
                        ? __('page', 'taw-core')
                        : node?.kind === 'part'
                          ? __('template part', 'taw-core')
                          : __('block', 'taw-core')
                    : a.kind === 'ambiguous'
                      ? __('ambiguous', 'taw-core')
                      : a.kind === 'mixed'
                        ? __('mixed', 'taw-core')
                        : __('element', 'taw-core');
        const name = a.kind === 'element' ? chipLabel(manifest, target) : shortName(manifest, target);

        return `${name} · ${kind}`;
    });
}

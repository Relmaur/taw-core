/**
 * What rendered an element (umbrella ADR-0006 § 7): the page script's half of the source map.
 *
 * One pass over the document reads the comment markers (`<!--taw-lens:N-->` … `<!--/taw-lens:N-->`) to know
 * which node every element and text sits in. An element's text is then compared with what its node read and
 * wrote, nearest scope first (a block's own fields beat its parent's):
 *
 * - equal to one field value or template text → that field or template text;
 * - equal to several in the same scope → **ambiguous**, with every candidate (Lens never picks one);
 * - made of known pieces (template text around a value) → **mixed**, with the pieces;
 * - an image whose file is an image field's → that field.
 *
 * The inspect card, the structure tree and (build step 4) what Claude receives are all built from this.
 */
import { chainOf, type Manifest, type MapNode, type Read, type TemplateTextEntry } from './manifest';

export interface FieldSource {
    kind: 'field';
    /** The node whose read it is. */
    node: number;
    read: Read;
    /** The field, or a repeater row's sub-field: `faq_items[1].answer`. */
    path: string;
    /** "Title", or "Items › Answer (row 2)". */
    label: string;
    /** Only part of a rich-text value. */
    part?: boolean;
}

export interface TemplateSource {
    kind: 'template';
    node: number;
    /** Relative to the theme, a key of the manifest's files. */
    file: string;
    line: number;
    text: string;
    how: TemplateTextEntry['kind'];
}

export type Source = FieldSource | TemplateSource;

export type Attribution =
    | { kind: 'field'; node: number; text: string; source: FieldSource }
    | { kind: 'template'; node: number; text: string; source: TemplateSource }
    | { kind: 'ambiguous'; node: number; text: string; sources: Source[] }
    | { kind: 'mixed'; node: number; text: string; sources: Source[] }
    /** A block, a template part, or the page itself. */
    | { kind: 'block'; node: number }
    /** Rendered by its node, but its text matches nothing TAW read or wrote. */
    | { kind: 'element'; node: number; text: string };

/** Field types whose stored value never shows as text. */
const NOT_TEXT = new Set(['checkbox', 'toggle', 'color', 'hidden', 'image', 'gallery', 'file', 'post_select']);
const SKIP = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'HEAD', 'TITLE']);
const PAGE_ELEMENTS = new Set(['HTML', 'HEAD', 'BODY']);
/** Text longer than this is a container's, not something one value rendered. */
const TEXT_MAX = 4000;

export const normalize = (text: string): string => text.replace(/\s+/g, ' ').trim();

/** Rich text as the page shows it: tags gone, entities decoded, whitespace collapsed. */
export function plainText(html: string, doc: Document = document): string {
    const template = doc.createElement('template');
    template.innerHTML = html;

    return normalize(template.content.textContent ?? '');
}

const isRich = (type: string, value: string): boolean => type === 'wysiwyg' || /<[a-z][^>]*>/i.test(value);

interface Candidate {
    source: FieldSource;
    text: string;
    rich: boolean;
}

/** A value as a repeater's rows, when it is one. */
function rowsOf(value: unknown): Record<string, unknown>[] | null {
    let rows = value;
    if (typeof rows === 'string' && /^\s*\[/.test(rows)) {
        try {
            rows = JSON.parse(rows);
        } catch {
            return null;
        }
    }

    return Array.isArray(rows) ? rows.filter((row) => typeof row === 'object' && row !== null) : null;
}

const fieldCache = new WeakMap<MapNode, Candidate[]>();

/** Every text one node's reads can put on the page (repeater rows expanded). */
export function fieldCandidates(node: MapNode, doc: Document = document): Candidate[] {
    const cached = fieldCache.get(node);
    if (cached) {
        return cached;
    }
    const out: Candidate[] = [];
    for (const read of node.reads) {
        if (NOT_TEXT.has(read.type)) {
            continue;
        }
        const rows = read.type === 'repeater' || read.subs ? rowsOf(read.value) : null;
        if (rows) {
            rows.forEach((row, i) => {
                for (const [sub, value] of Object.entries(row)) {
                    if (typeof value !== 'string' && typeof value !== 'number') {
                        continue;
                    }
                    const raw = String(value);
                    const subType = read.subs?.[sub]?.type ?? 'text';
                    if (!raw.trim() || NOT_TEXT.has(subType)) {
                        continue;
                    }
                    const subLabel = read.subs?.[sub]?.label ?? sub;
                    const rich = isRich(subType, raw);
                    out.push({
                        source: {
                            kind: 'field',
                            node: node.id,
                            read,
                            path: `${read.key}[${i}].${sub}`,
                            label: `${read.label} › ${subLabel} (row ${i + 1})`,
                        },
                        text: rich ? plainText(raw, doc) : normalize(raw),
                        rich,
                    });
                }
            });
            continue;
        }
        if (typeof read.value !== 'string' && typeof read.value !== 'number') {
            continue;
        }
        const raw = String(read.value);
        if (!raw.trim()) {
            continue;
        }
        const rich = isRich(read.type, raw);
        out.push({
            source: { kind: 'field', node: node.id, read, path: read.key, label: read.label },
            text: rich ? plainText(raw, doc) : normalize(raw),
            rich,
        });
    }
    fieldCache.set(node, out);

    return out;
}

/** The text a node's own template writes. */
export function templateCandidates(manifest: Manifest, node: MapNode): TemplateSource[] {
    const file = node.template;
    if (!file) {
        return [];
    }

    return (manifest.files[file]?.texts ?? []).map((entry) => ({
        kind: 'template' as const,
        node: node.id,
        file,
        line: entry.line,
        text: normalize(entry.text),
        how: entry.kind,
    }));
}

/** A stable key for a source: the same field or template text, wherever it was found. */
export function sourceKey(source: Source): string {
    return source.kind === 'field'
        ? `f:${source.read.store}:${source.read.object}:${source.path}`
        : `t:${source.file}:${source.line}:${source.text}`;
}

/** The key of the field itself (a repeater's rows share it). */
export const fieldKey = (read: Read): string => `f:${read.store}:${read.object}:${read.key}`;

function unique(sources: Source[]): Source[] {
    const seen = new Map<string, Source>();
    for (const source of sources) {
        const key = sourceKey(source);
        if (!seen.has(key)) {
            seen.set(key, source);
        }
    }

    return [...seen.values()];
}

/** A menu item beats the title of the post it links to (its label is often that title). */
function tieBreak(hits: Source[]): Source[] {
    const linked = new Set(
        hits.flatMap((hit) =>
            hit.kind === 'field' && hit.read.store === 'menu' && hit.read.target ? [hit.read.target] : [],
        ),
    );
    if (linked.size === 0) {
        return hits;
    }

    return hits.filter(
        (hit) =>
            !(
                hit.kind === 'field' &&
                hit.read.store === 'post' &&
                hit.read.key === 'post_title' &&
                linked.has(Number(hit.read.object))
            ),
    );
}

/** What wrote `text`, an element's whole text, inside node `nodeId`. */
export function classifyText(manifest: Manifest, nodeId: number, text: string, doc: Document = document): Attribution {
    const chain = chainOf(manifest, nodeId);
    for (const scope of chain) {
        const exact: Source[] = [];
        const partial: Source[] = [];
        for (const candidate of fieldCandidates(scope, doc)) {
            if (candidate.text === text) {
                exact.push(candidate.source);
            } else if (candidate.rich && text.length >= 2 && candidate.text.includes(text)) {
                partial.push({ ...candidate.source, part: true });
            }
        }
        for (const entry of templateCandidates(manifest, scope)) {
            if (entry.text === text) {
                exact.push(entry);
            }
        }
        const hits = unique(tieBreak(exact.length > 0 ? exact : partial));
        if (hits.length === 1) {
            const [source] = hits;
            return source.kind === 'field'
                ? { kind: 'field', node: scope.id, text, source }
                : { kind: 'template', node: scope.id, text, source };
        }
        if (hits.length > 1) {
            return { kind: 'ambiguous', node: scope.id, text, sources: hits };
        }
    }

    // Mixed: template text around values. The pieces found in the nearest scope that has any.
    for (const scope of chain) {
        const parts: Source[] = [];
        for (const candidate of fieldCandidates(scope, doc)) {
            if (candidate.text.length >= 2 && text.includes(candidate.text)) {
                parts.push(candidate.source);
            }
        }
        for (const entry of templateCandidates(manifest, scope)) {
            if (entry.text.length >= 2 && text.includes(entry.text)) {
                parts.push(entry);
            }
        }
        if (parts.length > 0) {
            return { kind: 'mixed', node: scope.id, text, sources: unique(parts) };
        }
    }

    return { kind: 'element', node: nodeId, text };
}

/** An element's own text, as the page shows it (scripts and styles left out). */
export function textOf(el: Element): string {
    let out = '';
    const walker = el.ownerDocument.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        if (!node.parentElement?.closest('script,style,noscript,template')) {
            out += (node as Text).data;
        }
    }

    return normalize(out);
}

/**
 * The page as the source map sees it: which node every element sits in, what each element is, and which
 * elements each field, template text and node rendered.
 */
export class PageMap {
    readonly manifest: Manifest;
    private readonly doc: Document;
    private readonly nodeByElement = new Map<Element, number>();
    private readonly roots = new Map<number, Element[]>();
    private readonly bySource = new Map<string, Element[]>();
    private readonly sourcesByKey = new Map<string, Source>();
    private readonly attributions = new Map<Element, Attribution>();
    /** Elements in document order, for sorting the tree. */
    private readonly order = new Map<Element, number>();

    constructor(doc: Document, manifest: Manifest, ignore?: Element | null) {
        this.doc = doc;
        this.manifest = manifest;
        this.scan(ignore ?? null);
    }

    /** The node an element sits in (0, the page, when it's outside every marker). */
    nodeOf(el: Element): number {
        return this.nodeByElement.get(el) ?? 0;
    }

    /** The outermost elements a node rendered (the page: its own top-level elements). */
    rootsOf(node: number): Element[] {
        return this.roots.get(node) ?? [];
    }

    /** Elements rendered by a source (by `sourceKey`) or a field (by `fieldKey`, every row). */
    elementsOf(key: string): Element[] {
        return this.bySource.get(key) ?? [];
    }

    /** Every source found on the page, by key, with where it first appears. */
    sources(): Map<string, Source> {
        return this.sourcesByKey;
    }

    position(el: Element): number {
        return this.order.get(el) ?? Number.MAX_SAFE_INTEGER;
    }

    /** What an element is. */
    attribute(el: Element): Attribution {
        const known = this.attributions.get(el);
        if (known) {
            return known;
        }
        const attribution = this.compute(el);
        this.attributions.set(el, attribution);

        return attribution;
    }

    /** Whether the element is one of its node's outermost elements. */
    isRoot(el: Element): boolean {
        const node = this.nodeOf(el);
        const parent = el.parentElement;

        return node !== 0 && (!parent || PAGE_ELEMENTS.has(parent.tagName) || this.nodeOf(parent) !== node);
    }

    private compute(el: Element): Attribution {
        if (PAGE_ELEMENTS.has(el.tagName)) {
            return { kind: 'block', node: 0 };
        }
        const node = this.nodeOf(el);
        if (el.tagName === 'IMG') {
            const image = this.imageField(el, node);
            if (image) {
                return { kind: 'field', node: image.node, text: '', source: image };
            }
        }
        const text = textOf(el);
        const byText = text && text.length <= TEXT_MAX ? classifyText(this.manifest, node, text, this.doc) : null;
        if (byText && (byText.kind === 'field' || byText.kind === 'template' || byText.kind === 'ambiguous')) {
            return byText;
        }
        if (this.isRoot(el)) {
            return { kind: 'block', node };
        }
        const link = el.tagName === 'A' ? this.linkField(el, node) : null;
        if (link) {
            return { kind: 'field', node: link.node, text, source: link };
        }
        if (byText?.kind === 'mixed') {
            return byText;
        }

        return { kind: 'element', node, text };
    }

    /** An image field whose file this image shows, in any size or format. */
    private imageField(el: Element, node: number): FieldSource | null {
        const src = `${el.getAttribute('src') ?? ''} ${el.getAttribute('srcset') ?? ''}`;
        for (const scope of chainOf(this.manifest, node)) {
            const read = scope.reads.find((r) => r.type === 'image' && r.stem && src.includes(`/${r.stem}`));
            if (read) {
                return { kind: 'field', node: scope.id, read, path: read.key, label: read.label };
            }
        }

        return null;
    }

    /** A URL field this link points at. */
    private linkField(el: Element, node: number): FieldSource | null {
        const href = el.getAttribute('href');
        if (!href) {
            return null;
        }
        for (const scope of chainOf(this.manifest, node)) {
            const read = scope.reads.find(
                (r) => (r.type === 'url' || r.type === 'link') && typeof r.value === 'string' && r.value === href,
            );
            if (read) {
                return { kind: 'field', node: scope.id, read, path: read.key, label: read.label };
            }
        }

        return null;
    }

    private add(key: string, el: Element): void {
        const list = this.bySource.get(key);
        if (!list) {
            this.bySource.set(key, [el]);
        } else if (!list.includes(el)) {
            list.push(el);
        }
    }

    private scan(ignore: Element | null): void {
        const stack = [0];
        const textParents = new Set<Element>();
        const walker = this.doc.createTreeWalker(
            this.doc,
            NodeFilter.SHOW_COMMENT | NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT,
        );
        let index = 0;
        for (let current = walker.nextNode(); current; current = walker.nextNode()) {
            if (current.nodeType === Node.COMMENT_NODE) {
                const marker = /^(\/)?taw-lens:(\d+)$/.exec((current as Comment).data);
                if (marker) {
                    if (marker[1]) {
                        const at = stack.lastIndexOf(Number(marker[2]));
                        if (at > 0) {
                            stack.length = at;
                        }
                    } else {
                        stack.push(Number(marker[2]));
                    }
                }
                continue;
            }
            if (current.nodeType === Node.ELEMENT_NODE) {
                const el = current as Element;
                if (ignore && (el === ignore || ignore.contains(el))) {
                    continue;
                }
                this.nodeByElement.set(el, stack[stack.length - 1]);
                this.order.set(el, index++);
                continue;
            }
            const parent = current.parentElement;
            if (
                parent &&
                normalize((current as Text).data) &&
                !SKIP.has(parent.tagName) &&
                !parent.closest('script,style,noscript,template,head') &&
                !(ignore && ignore.contains(parent))
            ) {
                textParents.add(parent);
            }
        }

        for (const [el, node] of this.nodeByElement) {
            if (
                node !== 0 &&
                this.isRoot(el) &&
                !SKIP.has(el.tagName) &&
                el.tagName !== 'LINK' &&
                el.tagName !== 'META'
            ) {
                const list = this.roots.get(node) ?? [];
                list.push(el);
                this.roots.set(node, list);
            }
        }
        // The page's own top-level elements: in node 0, inside an element of another node (or of the page).
        for (const [el, node] of this.nodeByElement) {
            if (node === 0 && !PAGE_ELEMENTS.has(el.tagName) && !SKIP.has(el.tagName) && el.parentElement) {
                const parent = el.parentElement;
                if (PAGE_ELEMENTS.has(parent.tagName) || this.nodeOf(parent) !== 0) {
                    const list = this.roots.get(0) ?? [];
                    list.push(el);
                    this.roots.set(0, list);
                }
            }
        }

        // Text, images (by file) and links (by URL field): everything a source can render.
        const indexed = [...new Set([...textParents, ...this.doc.querySelectorAll('img, a[href]')])].filter(
            (el) => !(ignore && ignore.contains(el)),
        );
        for (const el of indexed) {
            const attribution = this.attribute(el);
            const sources =
                attribution.kind === 'field' || attribution.kind === 'template'
                    ? [attribution.source]
                    : attribution.kind === 'ambiguous'
                      ? attribution.sources
                      : [];
            for (const source of sources) {
                const key = sourceKey(source);
                this.add(key, el);
                if (!this.sourcesByKey.has(key)) {
                    this.sourcesByKey.set(key, source);
                }
                if (source.kind === 'field') {
                    this.add(fieldKey(source.read), el);
                }
            }
        }
    }
}

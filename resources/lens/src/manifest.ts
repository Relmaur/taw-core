/**
 * The source map's page manifest, as taw/core prints it (TAW\Core\Lens\SourceMap, umbrella ADR-0006 § 7):
 * one `<script type="application/json" id="taw-lens-map">` holding every node (the page, template parts,
 * blocks) with the values read while it rendered, and every template file with its text and lines.
 */

export const MANIFEST_ID = 'taw-lens-map';
export const MANIFEST_VERSION = 1;

/** A value read while a node rendered: a TAW field or option, or WordPress data (titles, menu items). */
export interface Read {
    store: 'post' | 'term' | 'user' | 'option' | 'wp' | 'menu';
    /** The post, term or user id; the options page id; the menu id. */
    object: number | string;
    key: string;
    /** The field type (text, wysiwyg, image, repeater…), or `wp` for WordPress's own data. */
    type: string;
    label: string;
    value: unknown;
    /** Where a person changes it in wp-admin. */
    admin: string;
    /** The field's default was shown: nothing is stored yet. */
    default?: boolean;
    /** An image's file name without size or extension. */
    stem?: string;
    /** A menu item that links to this post. */
    target?: number;
    /** Can be edited on the page (TAW\Core\Lens\FieldWriter): as plain text, or as rich text. */
    edit?: 'text' | 'rich';
    /** A rich-text field's formats: bold, italic, link, lists. */
    formats?: string[];
    /** A repeater's sub-fields. */
    subs?: Record<string, { label: string; type: string; edit?: 'text' | 'rich'; formats?: string[] }>;
}

export interface MapNode {
    id: number;
    kind: 'page' | 'part' | 'block';
    /** A block's id, a part's path without `.php`, the main template's file name. */
    name: string;
    /** What a person calls it: "Hero", "Header", the page's title. */
    label: string;
    parent: number | null;
    /** The file it renders, relative to the theme (a key of `files`). */
    template: string | null;
    /** A block's files: its class first, then the rest of its folder. */
    files?: string[];
    class?: string;
    post?: number | null;
    reads: Read[];
}

export interface TemplateTextEntry {
    text: string;
    line: number;
    kind: 'html' | 'i18n' | 'literal';
}

export interface MapFile {
    abs: string;
    texts?: TemplateTextEntry[];
    /** Source lines around each text, by line number. */
    lines?: Record<string, string>;
}

export interface Manifest {
    version: number;
    nodes: Map<number, MapNode>;
    files: Record<string, MapFile>;
}

const isObject = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

/** Parses the manifest; null when it's missing, broken or a version this script doesn't know. */
export function parseManifest(json: string | null | undefined): Manifest | null {
    if (!json) {
        return null;
    }
    let raw: unknown;
    try {
        raw = JSON.parse(json);
    } catch {
        return null;
    }
    if (!isObject(raw) || raw.version !== MANIFEST_VERSION) {
        return null;
    }
    // PHP prints nodes 0…n as a list; any other set of ids as an object.
    const list = Array.isArray(raw.nodes) ? raw.nodes : isObject(raw.nodes) ? Object.values(raw.nodes) : [];
    const nodes = new Map<number, MapNode>();
    for (const node of list) {
        if (isObject(node) && typeof node.id === 'number') {
            nodes.set(node.id, { ...node, reads: Array.isArray(node.reads) ? node.reads : [] } as unknown as MapNode);
        }
    }
    if (!nodes.has(0)) {
        return null;
    }

    return {
        version: MANIFEST_VERSION,
        nodes,
        files: isObject(raw.files) ? (raw.files as Record<string, MapFile>) : {},
    };
}

export const readManifest = (doc: Document): Manifest | null =>
    parseManifest(doc.getElementById(MANIFEST_ID)?.textContent);

/** The node's ancestors, nearest first, starting with itself. */
export function chainOf(manifest: Manifest, id: number): MapNode[] {
    const chain: MapNode[] = [];
    const seen = new Set<number>();
    for (let node = manifest.nodes.get(id); node && !seen.has(node.id);) {
        chain.push(node);
        seen.add(node.id);
        node = node.parent === null ? undefined : manifest.nodes.get(node.parent);
    }

    return chain;
}

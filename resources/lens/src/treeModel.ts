/**
 * The structure tree's model (component 01, screen 04): TAW's view of the page, built from the source map.
 * The page → template parts and blocks → the fields and template text each one put on the page, in page
 * order. No raw DOM nodes.
 */
import { fieldName, isWordPress, nodeName, truncate, type Target } from './describe';
import { __ } from './i18n';
import type { MapNode } from './manifest';
import { fieldKey, sourceKey, type FieldSource, type PageMap, type Source } from './sourcemap';

export type TreeKind = 'page' | 'part' | 'block' | 'field' | 'wp' | 'template';

export interface TreeNode {
    id: string;
    kind: TreeKind;
    name: string;
    /** The mono detail: a file, a block id, "field". */
    detail: string;
    children: TreeNode[];
    target: Target;
    /** First element's place in the document, for ordering. */
    position: number;
}

export interface TreeRow {
    node: TreeNode;
    depth: number;
    parent: string | null;
    expanded: boolean;
}

const fileName = (path: string | null): string => (path ? (path.split('/').pop() ?? path) : '');

function nodeRow(map: PageMap, node: MapNode): TreeNode {
    const elements = node.id === 0 ? [] : map.rootsOf(node.id);
    const kind: TreeKind = node.kind;

    return {
        id: `n:${node.id}`,
        kind,
        name: nodeName(node),
        detail: kind === 'block' ? node.name : kind === 'page' ? fileName(node.template) : (node.template ?? node.name),
        children: [],
        target: { key: `n:${node.id}`, attribution: { kind: 'block', node: node.id }, elements, at: node.id },
        position: Math.min(...elements.map((el) => map.position(el)), Number.MAX_SAFE_INTEGER),
    };
}

function itemRow(map: PageMap, key: string, source: Source, elements: Element[]): TreeNode {
    const position = Math.min(...elements.map((el) => map.position(el)), Number.MAX_SAFE_INTEGER);
    const at = elements[0] ? map.nodeOf(elements[0]) : source.node;
    if (source.kind === 'template') {
        return {
            id: key,
            kind: 'template',
            name: truncate(source.text, 40),
            detail: `${source.file}:${source.line}`,
            children: [],
            target: {
                key,
                attribution: { kind: 'template', node: source.node, text: source.text, source },
                elements,
                at,
            },
            position,
        };
    }
    // A field's row stands for the whole field: every row of a repeater, every element it rendered.
    const field: FieldSource = {
        kind: 'field',
        node: source.node,
        read: source.read,
        path: source.read.key,
        label: source.read.label,
    };
    const wp = isWordPress(source.read);

    return {
        id: key,
        kind: wp ? 'wp' : 'field',
        name: fieldName(source.read),
        detail: wp ? __('WordPress', 'taw-core') : __('field', 'taw-core'),
        children: [],
        target: { key, attribution: { kind: 'field', node: source.node, text: '', source: field }, elements, at },
        position,
    };
}

/** The whole tree, rooted at the page. */
export function buildTree(map: PageMap): TreeNode {
    const rows = new Map<number, TreeNode>();
    for (const node of map.manifest.nodes.values()) {
        rows.set(node.id, nodeRow(map, node));
    }

    // Fields (grouped: one row per field) and template text, under the node that read or wrote them.
    const items = new Map<string, { node: number; source: Source }>();
    for (const source of map.sources().values()) {
        const itemKey = source.kind === 'field' ? fieldKey(source.read) : sourceKey(source);
        if (!items.has(itemKey)) {
            items.set(itemKey, { node: source.node, source });
        }
    }
    for (const [key, { node, source }] of items) {
        const parent = rows.get(node);
        const elements = map.elementsOf(key);
        if (parent && elements.length > 0) {
            parent.children.push(itemRow(map, key, source, elements));
        }
    }

    for (const node of map.manifest.nodes.values()) {
        if (node.parent !== null) {
            rows.get(node.parent)?.children.push(rows.get(node.id)!);
        }
    }

    const sort = (row: TreeNode): number => {
        for (const child of row.children) {
            row.position = Math.min(row.position, sort(child));
        }
        // Page order; a block before a field shown by its outermost element.
        const isNode = (n: TreeNode) => n.kind === 'page' || n.kind === 'part' || n.kind === 'block';
        row.children.sort((a, b) => a.position - b.position || Number(isNode(b)) - Number(isNode(a)));

        return row.position;
    };
    const root = rows.get(0)!;
    sort(root);

    return root;
}

const matches = (node: TreeNode, query: string): boolean =>
    node.name.toLowerCase().includes(query) || node.detail.toLowerCase().includes(query);

/**
 * The rows to show: expanded nodes open; with a filter, every match and the nodes above it, all open.
 */
export function visibleRows(root: TreeNode, expanded: ReadonlySet<string>, filter = ''): TreeRow[] {
    const query = filter.trim().toLowerCase();
    const out: TreeRow[] = [];
    const keep = new Set<string>();
    if (query) {
        const mark = (node: TreeNode): boolean => {
            let any = matches(node, query);
            for (const child of node.children) {
                any = mark(child) || any;
            }
            if (any) {
                keep.add(node.id);
            }

            return any;
        };
        mark(root);
    }
    const walk = (node: TreeNode, depth: number, parent: string | null) => {
        if (query && !keep.has(node.id)) {
            return;
        }
        const open = query ? true : expanded.has(node.id);
        out.push({ node, depth, parent, expanded: open && node.children.length > 0 });
        if (open) {
            for (const child of node.children) {
                walk(child, depth + 1, node.id);
            }
        }
    };
    walk(root, 0, null);

    return out;
}

/** The ids of the rows above the one with `id` (to open them when it's selected elsewhere). */
export function ancestorsOf(root: TreeNode, id: string): string[] {
    const path: string[] = [];
    const find = (node: TreeNode): boolean => {
        if (node.id === id) {
            return true;
        }
        for (const child of node.children) {
            if (find(child)) {
                path.unshift(node.id);
                return true;
            }
        }

        return false;
    };

    return find(root) ? path : [];
}

/** The tree row that stands for a target, if any. */
export function rowIdFor(target: Target): string | null {
    const a = target.attribution;
    if (a.kind === 'block') {
        return `n:${a.node}`;
    }
    if (a.kind === 'field') {
        return fieldKey(a.source.read);
    }
    if (a.kind === 'template') {
        return sourceKey(a.source);
    }

    return null;
}

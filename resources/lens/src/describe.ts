/**
 * How Lens names things, shared by the label chips, the inspect card and the structure tree so they never
 * disagree. Everything comes from the source map.
 */
import { __ } from './i18n';
import { chainOf, type Manifest, type MapNode, type Read } from './manifest';
import type { Attribution, Source } from './sourcemap';

/** A selection: what it is, and the page elements it rings. */
export interface Target {
    key: string;
    attribution: Attribution;
    elements: Element[];
    /** The node the elements sit in (where the template rendered them). */
    at: number;
}

export const truncate = (text: string, max: number): string =>
    text.length > max ? `${text.slice(0, max - 1).trimEnd()}…` : text;

export const isWordPress = (read: Read): boolean => read.store === 'wp' || read.store === 'menu' || read.type === 'wp';

/** What a person calls a field type. */
export function typeLabel(type: string): string {
    switch (type) {
        case 'wysiwyg':
            return __('rich text', 'taw-core');
        case 'textarea':
            return __('long text', 'taw-core');
        case 'url':
        case 'link':
            return __('link', 'taw-core');
        case 'image':
            return __('image', 'taw-core');
        case 'repeater':
            return __('repeater', 'taw-core');
        case 'number':
            return __('number', 'taw-core');
        case 'select':
        case 'radio':
            return __('choice', 'taw-core');
        default:
            return __('text', 'taw-core');
    }
}

/** A field's own name: its key, or for WordPress data what it is ("Site Title", menu item "Home"). */
export function fieldName(read: Read, path = read.key): string {
    if (read.store === 'menu') {
        return `${__('Menu item', 'taw-core')} “${truncate(String(read.value ?? ''), 32)}”`;
    }
    if (isWordPress(read)) {
        return read.key === 'post_title' ? __('Post title', 'taw-core') : read.label;
    }

    return path;
}

export const sourceName = (source: Source): string =>
    source.kind === 'field' ? fieldName(source.read, source.path) : `“${truncate(source.text, 40)}”`;

export const nodeName = (node: MapNode): string => node.label || node.name;

/** "Home › Hero", with an optional last step ("Home › Hero › Title"). */
export function location(manifest: Manifest, nodeId: number, leaf?: string): string {
    const steps = chainOf(manifest, nodeId)
        .reverse()
        .map(nodeName)
        .filter((name) => name !== '');
    if (leaf) {
        steps.push(leaf);
    }

    return steps.join(' › ');
}

/** The nearest block or part's name, for the label chip: `hero`, `header`. */
function scopeName(manifest: Manifest, nodeId: number): string {
    const node = chainOf(manifest, nodeId).find((n) => n.kind !== 'page') ?? manifest.nodes.get(0);

    return node ? node.name : '';
}

/** The label chip above a ring: `hero › hero_title`, `faq › "Questions"`, `hero`. */
export function chipLabel(manifest: Manifest, target: Target): string {
    const a = target.attribution;
    const scope = scopeName(manifest, a.kind === 'block' ? a.node : target.at);
    const leaf =
        a.kind === 'field' || a.kind === 'template'
            ? sourceName(a.source)
            : a.kind === 'ambiguous'
              ? __('ambiguous', 'taw-core')
              : a.kind === 'mixed'
                ? __('mixed', 'taw-core')
                : a.kind === 'element'
                  ? (target.elements[0]?.tagName.toLowerCase() ?? '')
                  : '';

    return leaf ? `${scope} › ${leaf}` : scope;
}

/** The short name a chip in the panel shows. */
export function shortName(manifest: Manifest, target: Target): string {
    const a = target.attribution;
    if (a.kind === 'field' || a.kind === 'template') {
        return sourceName(a.source);
    }
    if (a.kind === 'block') {
        const node = manifest.nodes.get(a.node);
        return node ? nodeName(node) : '';
    }
    if (a.kind === 'element') {
        return `<${target.elements[0]?.tagName.toLowerCase() ?? '?'}>`;
    }

    return `“${truncate(a.text, 24)}”`;
}

/** "Open in editor": the developer's editor link with the file's absolute path and line. */
export function editorUrl(pattern: string, abs: string, line = 1): string {
    return pattern.replace('{path}', encodeURI(abs)).replace('{line}', String(line));
}

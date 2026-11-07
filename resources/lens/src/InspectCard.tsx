/**
 * The inspect card (component 03, screen 06): in the panel, above the composer, never on the page. It's
 * Lens's human path (umbrella ADR-0004): every card says where to change the selection by hand, from the
 * same source map Claude receives.
 *
 * Field → its value and its wp-admin screen. Template text → the file and line, with the lines around it.
 * Block (or template part, or the page) → its files and fields. When the map can't say for sure, the card
 * says so: ambiguous lists every candidate, mixed lists the pieces; Lens never picks one.
 */
import type { ComponentChildren } from 'preact';
import {
    editorUrl,
    fieldName,
    isWordPress,
    location,
    nodeName,
    sourceName,
    truncate,
    typeLabel,
    type Target,
} from './describe';
import { __ } from './i18n';
import { CloseIcon } from './icons';
import type { Manifest } from './manifest';
import { plainText, type FieldSource, type Source, type TemplateSource } from './sourcemap';

interface Props {
    manifest: Manifest;
    target: Target;
    editor: string;
    onClose(): void;
    onShowInTree(target: Target): void;
}

function Head({ title, mono, badge, onClose }: { title: string; mono?: boolean; badge: string; onClose?: () => void }) {
    return (
        <div class="card-head">
            <span class={mono ? 'card-title mono' : 'card-title'}>{title}</span>
            <span class="card-badge">{badge}</span>
            {onClose && (
                <button
                    type="button"
                    class="card-close"
                    aria-label={__('Clear selection', 'taw-core')}
                    onClick={onClose}
                >
                    <CloseIcon />
                </button>
            )}
        </div>
    );
}

const Actions = ({ children }: { children: ComponentChildren }) => <div class="card-actions">{children}</div>;

/** Editing in place arrives with build step 5; until then the button says so instead of doing nothing. */
const EditHere = () => (
    <button
        type="button"
        class="card-button primary"
        aria-disabled="true"
        title={__('Editing in place is coming in a later build', 'taw-core')}
    >
        {__('Edit here', 'taw-core')}
    </button>
);

const External = ({ href, children, primary }: { href: string; children: ComponentChildren; primary?: boolean }) => (
    <a class={primary ? 'card-button primary' : 'card-button'} href={href} target="_blank" rel="noopener noreferrer">
        {children}
    </a>
);

function fieldValue(source: FieldSource): string {
    let value: unknown = source.read.value;
    const row = /\[(\d+)\]\.(.+)$/.exec(source.path);
    if (row) {
        try {
            const rows = typeof value === 'string' ? JSON.parse(value) : value;
            value = Array.isArray(rows) ? rows[Number(row[1])]?.[row[2]] : value;
        } catch {
            // Shown as stored.
        }
    } else if (source.read.type === 'repeater') {
        try {
            const rows = typeof value === 'string' ? JSON.parse(value) : value;
            if (Array.isArray(rows)) {
                return rows.length === 1
                    ? __('1 row', 'taw-core')
                    : __('%d rows', 'taw-core').replace('%d', String(rows.length));
            }
        } catch {
            // Shown as stored.
        }
    }
    if (source.read.type === 'image') {
        return source.read.stem ?? String(value ?? '');
    }
    const text = typeof value === 'string' || typeof value === 'number' ? String(value) : JSON.stringify(value ?? '');

    return truncate(/<[a-z][^>]*>/i.test(text) ? plainText(text) : text, 600);
}

/** One line per candidate or piece, each with its way to change it by hand. */
function SourceList({ manifest, sources, editor }: { manifest: Manifest; sources: Source[]; editor: string }) {
    return (
        <ul class="card-sources">
            {sources.map((source) => (
                <li key={source.kind === 'field' ? source.path + source.read.object : `${source.file}:${source.line}`}>
                    <span class="card-source-name mono">{sourceName(source)}</span>
                    <span class="card-source-where">
                        {source.kind === 'field'
                            ? location(manifest, source.node, source.label)
                            : `${source.file}:${source.line}`}
                    </span>
                    {source.kind === 'field' ? (
                        source.read.admin && (
                            <a class="card-link" href={source.read.admin} target="_blank" rel="noopener noreferrer">
                                {__('Open in wp-admin', 'taw-core')}
                            </a>
                        )
                    ) : (
                        <TemplateLink manifest={manifest} source={source} editor={editor} />
                    )}
                </li>
            ))}
        </ul>
    );
}

function TemplateLink({ manifest, source, editor }: { manifest: Manifest; source: TemplateSource; editor: string }) {
    const abs = manifest.files[source.file]?.abs;

    return abs ? (
        <a class="card-link" href={editorUrl(editor, abs, source.line)}>
            {__('Open in editor', 'taw-core')}
        </a>
    ) : null;
}

/** "Rendered by Blocks/Hero/index.php": the template that printed the element. */
function RenderedBy({ manifest, node }: { manifest: Manifest; node: number }) {
    const template = manifest.nodes.get(node)?.template;

    return template ? (
        <div class="card-meta mono">
            {__('Rendered by', 'taw-core')} {template}
        </div>
    ) : null;
}

export function InspectCard({ manifest, target, editor, onClose, onShowInTree }: Props) {
    const a = target.attribution;
    const at = manifest.nodes.get(target.at);

    if (a.kind === 'field') {
        const { read } = a.source;
        const wp = isWordPress(read);
        const name = fieldName(read, a.source.path);
        const kind = wp
            ? __('WordPress', 'taw-core')
            : `${__('Field', 'taw-core')} · ${typeLabel(read.subs?.[a.source.path.split('.').pop() ?? '']?.type ?? read.type)}`;

        return (
            <section class="card" aria-label={__('Inspect', 'taw-core')}>
                <Head title={name} mono={!wp} badge={kind} onClose={onClose} />
                <div class="card-location">{location(manifest, a.source.node, a.source.label)}</div>
                <div class="card-value">
                    {fieldValue(a.source) || <span class="card-empty">{__('(empty)', 'taw-core')}</span>}
                </div>
                {read.default && (
                    <div class="card-note">{__('The field’s default: nothing is saved yet.', 'taw-core')}</div>
                )}
                {a.source.part && <div class="card-note">{__('Part of this rich-text field.', 'taw-core')}</div>}
                <RenderedBy manifest={manifest} node={target.at} />
                <Actions>
                    {wp ? null : <EditHere />}
                    {read.admin && <External href={read.admin}>{__('Open in wp-admin', 'taw-core')}</External>}
                </Actions>
            </section>
        );
    }

    if (a.kind === 'template') {
        const { source } = a;
        const file = manifest.files[source.file];
        const lines = file?.lines ?? {};
        const around = [source.line - 1, source.line, source.line + 1].filter((n) => lines[String(n)] !== undefined);

        return (
            <section class="card" aria-label={__('Inspect', 'taw-core')}>
                <Head
                    title={`“${truncate(source.text, 60)}”`}
                    badge={
                        source.how === 'i18n'
                            ? __('Template text · translatable', 'taw-core')
                            : __('Template text', 'taw-core')
                    }
                    onClose={onClose}
                />
                <div class="card-location">{location(manifest, source.node)}</div>
                {around.length > 0 && (
                    <div class="card-code mono" role="group" aria-label={`${source.file}:${source.line}`}>
                        {around.map((n) => (
                            <div key={n} class="card-code-line" data-current={n === source.line ? '' : undefined}>
                                <span class="card-code-number">{n}</span>
                                <span class="card-code-text">{lines[String(n)]}</span>
                            </div>
                        ))}
                    </div>
                )}
                <div class="card-meta mono">
                    {source.file}:{source.line}
                </div>
                <Actions>
                    <EditHere />
                    {file?.abs && (
                        <External href={editorUrl(editor, file.abs, source.line)}>
                            {__('Open in editor', 'taw-core')}
                        </External>
                    )}
                </Actions>
            </section>
        );
    }

    if (a.kind === 'block') {
        const node = manifest.nodes.get(a.node);
        if (!node) {
            return null;
        }
        const badge =
            node.kind === 'block'
                ? `${__('Block', 'taw-core')} · ${node.name}`
                : node.kind === 'part'
                  ? __('Template part', 'taw-core')
                  : __('Page', 'taw-core');
        const files = node.files ?? (node.template ? [node.template] : []);
        const fields = [
            ...new Set(node.reads.filter((r) => !isWordPress(r) && r.store !== 'option').map((r) => r.key)),
        ];
        const template = node.template ? manifest.files[node.template] : undefined;

        return (
            <section class="card" aria-label={__('Inspect', 'taw-core')}>
                <Head title={nodeName(node)} badge={badge} onClose={onClose} />
                <div class="card-location">{location(manifest, node.id)}</div>
                <ul class="card-files mono">
                    {files.map((path) => {
                        const abs = manifest.files[path]?.abs;
                        return <li key={path}>{abs ? <a href={editorUrl(editor, abs)}>{path}</a> : path}</li>;
                    })}
                </ul>
                {fields.length > 0 && (
                    <div class="card-meta">
                        {__('Fields:', 'taw-core')} {fields.join(', ')}
                    </div>
                )}
                <Actions>
                    {template?.abs && (
                        <External href={editorUrl(editor, template.abs)}>{__('Open in editor', 'taw-core')}</External>
                    )}
                    <button type="button" class="card-button" onClick={() => onShowInTree(target)}>
                        {__('Show in tree', 'taw-core')}
                    </button>
                </Actions>
            </section>
        );
    }

    if (a.kind === 'ambiguous') {
        return (
            <section class="card" aria-label={__('Inspect', 'taw-core')}>
                <Head title={`“${truncate(a.text, 60)}”`} badge={__('Ambiguous', 'taw-core')} onClose={onClose} />
                <div class="card-location">{location(manifest, a.node)}</div>
                <div class="card-note">
                    {__(
                        'More than one source holds this exact text, so Lens can’t tell which one rendered it. It doesn’t guess:',
                        'taw-core',
                    )}
                </div>
                <SourceList manifest={manifest} sources={a.sources} editor={editor} />
            </section>
        );
    }

    if (a.kind === 'mixed') {
        return (
            <section class="card" aria-label={__('Inspect', 'taw-core')}>
                <Head title={`“${truncate(a.text, 60)}”`} badge={__('Mixed', 'taw-core')} onClose={onClose} />
                <div class="card-location">{location(manifest, target.at)}</div>
                <div class="card-note">{__('Template text around values. Made of:', 'taw-core')}</div>
                <SourceList manifest={manifest} sources={a.sources} editor={editor} />
                <RenderedBy manifest={manifest} node={target.at} />
            </section>
        );
    }

    // Text TAW didn't read or write: computed in the template, or added by JavaScript.
    const tag = target.elements[0]?.tagName.toLowerCase() ?? '';
    const template = at?.template ? manifest.files[at.template] : undefined;

    return (
        <section class="card" aria-label={__('Inspect', 'taw-core')}>
            <Head title={`<${tag}>`} mono badge={__('Element', 'taw-core')} onClose={onClose} />
            <div class="card-location">{location(manifest, target.at)}</div>
            <div class="card-note">
                {a.text
                    ? __(
                          'Its text isn’t a TAW field or template text: it may be computed in the template, or added by JavaScript.',
                          'taw-core',
                      )
                    : __('No text of its own. Its template rendered it.', 'taw-core')}
            </div>
            <RenderedBy manifest={manifest} node={target.at} />
            <Actions>
                {template?.abs && (
                    <External href={editorUrl(editor, template.abs)}>{__('Open in editor', 'taw-core')}</External>
                )}
            </Actions>
        </section>
    );
}

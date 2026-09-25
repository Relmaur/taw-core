/**
 * A tiny fake window.wp. Components are plain HTML exposing the props the
 * panel passes; `core/editor` is a fake store whose state tests set through
 * `editor` (useSelect runs the selector against it; editPost is a spy).
 */
import { createRequire } from 'node:module';
import type { ReactNode } from 'react';
import { vi } from 'vitest';

const require = createRequire(import.meta.url);
const React: typeof import('react') = require('react');

export const editor = {
    palette: [] as Array<{ name: string; slug: string; color: string }>,
    settings: {} as Record<string, unknown>,
    edited: {} as Record<string, unknown>,
    saved: {} as Record<string, unknown>,
};

export const editPost = vi.fn();

/** Media records by id for core-data's getMedia (missing id = still loading unless listed in `gone`). */
export const media = {
    records: {} as Record<number, Record<string, unknown>>,
    gone: [] as number[],
    /** What the fake media modal returns when opened. */
    pick: [] as Array<{ id: number }>,
};

/** GET paths → responses for the fake apiFetch (a function gets the path). */
export const api = {
    routes: [] as Array<[RegExp, unknown]>,
    calls: [] as string[],
};

export const apiFetch = vi.fn((options: { path: string }) => {
    api.calls.push(options.path);
    const route = api.routes.find(([pattern]) => pattern.test(options.path));
    if (!route) return Promise.reject(new Error(`no fake route for ${options.path}`));
    const body = typeof route[1] === 'function' ? (route[1] as (path: string) => unknown)(options.path) : route[1];
    return body instanceof Error ? Promise.reject(body) : Promise.resolve(body);
});

/** The fake block API: tests set how HTML becomes blocks and blocks become markup. */
export const blocksApi = {
    rawHandler: vi.fn<(args: { HTML: string }) => unknown[]>(() => []),
    serialize: vi.fn<(blocks: unknown[]) => string>(() => ''),
    createBlock: vi.fn((name: string, attributes: Record<string, unknown> = {}) => ({
        clientId: `new-${name}`,
        name,
        attributes,
        innerBlocks: [],
    })),
};

/** The last props the fake BlockEditorProvider received. */
export const blockEditorProps: { current: Record<string, unknown> | null } = { current: null };

export function resetEditor(): void {
    editor.palette = [];
    editor.settings = {};
    editor.edited = { meta: {} };
    editor.saved = {};
    editPost.mockReset();
    media.records = {};
    media.gone = [];
    media.pick = [];
    api.routes = [];
    api.calls = [];
    apiFetch.mockClear();
    blocksApi.rawHandler.mockReset().mockReturnValue([]);
    blocksApi.serialize.mockReset().mockReturnValue('');
    blocksApi.createBlock.mockClear();
    blockEditorProps.current = null;
}

const coreEditor = {
    getEditorSettings: () => editor.settings,
    getEditedPostAttribute: (key: string) => editor.edited[key],
    getCurrentPostAttribute: (key: string) => editor.saved[key],
};

const blockEditor = {
    getSettings: () => ({ colors: editor.palette }),
};

const core = {
    getEntityRecord: (_kind: string, _name: string, id: number) => media.records[id],
    hasFinishedResolution: (_selector: string, args: [string, string, number]) => media.gone.includes(args[2]),
};

const stores: Record<string, unknown> = { 'core/editor': coreEditor, 'core/block-editor': blockEditor, core };

const data = {
    useSelect: (mapSelect: (select: (store: string) => unknown) => unknown) =>
        mapSelect((store: string) => stores[store] ?? {}),
    useDispatch: () => ({ editPost }),
};

type Props = Record<string, unknown> & { children?: ReactNode };
const text = (value: unknown) => (typeof value === 'string' ? value : '');

const components = {
    PanelBody: ({ title, children }: Props) => (
        <section aria-label={text(title)}>
            <h2>{title as ReactNode}</h2>
            {children}
        </section>
    ),
    TabPanel: ({ tabs, children }: Props) => (
        <div role="tablist">
            {(tabs as Array<{ name: string; title: string }>).map((tab) => (
                <div key={tab.name} role="tabpanel" aria-label={tab.title}>
                    {(children as unknown as (tab: { name: string }) => ReactNode)(tab)}
                </div>
            ))}
        </div>
    ),
    TextControl: ({ label, value, onChange, type, disabled, help, placeholder }: Props) => (
        <label>
            {label as ReactNode}
            <input
                type={text(type) || 'text'}
                placeholder={text(placeholder) || undefined}
                value={value as string}
                disabled={disabled as boolean}
                onChange={(event) => (onChange as (v: string) => void)(event.target.value)}
            />
            {help ? <small>{help as ReactNode}</small> : null}
        </label>
    ),
    TextareaControl: ({ label, value, onChange }: Props) => (
        <label>
            {label as ReactNode}
            <textarea
                value={value as string}
                onChange={(event) => (onChange as (v: string) => void)(event.target.value)}
            />
        </label>
    ),
    SelectControl: ({ label, value, options, onChange }: Props) => (
        <label>
            {label as ReactNode}
            <select value={value as string} onChange={(event) => (onChange as (v: string) => void)(event.target.value)}>
                {(options as Array<{ value: string; label: string }>).map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    ),
    ToggleControl: ({ label, checked, onChange, disabled }: Props) => (
        <label>
            {label as ReactNode}
            <input
                type="checkbox"
                disabled={disabled as boolean}
                checked={checked as boolean}
                onChange={(event) => (onChange as (v: boolean) => void)(event.target.checked)}
            />
        </label>
    ),
    RangeControl: ({ label, value, min, max, onChange }: Props) => (
        <label>
            {label as ReactNode}
            <input
                type="range"
                value={value as number}
                min={min as number}
                max={max as number}
                onChange={(event) => (onChange as (v: number) => void)(Number(event.target.value))}
            />
        </label>
    ),
    BaseControl: ({ label, children }: Props) => (
        <fieldset>
            <legend>{label as ReactNode}</legend>
            {children}
        </fieldset>
    ),
    Dropdown: ({ renderToggle, renderContent }: Props) => (
        <div>
            {(renderToggle as (a: { isOpen: boolean; onToggle: () => void }) => ReactNode)({
                isOpen: false,
                onToggle: () => {},
            })}
            <div data-testid="dropdown-content">
                {(renderContent as (a: { onClose: () => void }) => ReactNode)({ onClose: () => {} })}
            </div>
        </div>
    ),
    Button: ({ children, onClick, disabled, label, id, role }: Props) => (
        <button
            type="button"
            id={id as string}
            role={role as string}
            aria-label={label as string}
            disabled={disabled as boolean}
            onClick={onClick as () => void}
        >
            {children}
        </button>
    ),
    Modal: ({ title, children, onRequestClose }: Props) => (
        <div role="dialog" aria-label={text(title)}>
            <button type="button" onClick={onRequestClose as () => void}>
                Close dialog
            </button>
            {children}
        </div>
    ),
    SearchControl: ({ label, value, onChange, onFocus, placeholder }: Props) => (
        <input
            type="search"
            aria-label={text(label)}
            placeholder={text(placeholder)}
            value={value as string}
            onFocus={onFocus as () => void}
            onChange={(event) => (onChange as (v: string) => void)(event.target.value)}
        />
    ),
    Spinner: () => <span role="progressbar" />,
    ColorIndicator: ({ colorValue }: Props) => <span data-testid="swatch" data-color={colorValue as string} />,
    ColorPalette: ({ colors, onChange }: Props) => (
        <div>
            {(colors as Array<{ name: string; color: string }>).map((c) => (
                <button key={c.color} type="button" onClick={() => (onChange as (v: string) => void)(c.color)}>
                    {c.name}
                </button>
            ))}
            <input
                aria-label="custom color"
                onChange={(event) => (onChange as (v?: string) => void)(event.target.value || undefined)}
            />
        </div>
    ),
    DatePicker: ({ onChange }: Props) => (
        <button type="button" onClick={() => (onChange as (v: string) => void)('2026-03-05T00:00:00')}>
            pick 5 March 2026
        </button>
    ),
    Notice: ({ children }: Props) => <div role="note">{children}</div>,
    Dashicon: ({ icon }: Props) => <span data-dashicon={icon as string} />,
};

const blockEditorApi = {
    MediaUploadCheck: ({ children }: Props) => <>{children}</>,
    MediaUpload: ({ render: renderToggle, onSelect, multiple }: Props) =>
        (renderToggle as (a: { open: () => void }) => ReactNode)({
            open: () => (onSelect as (m: unknown) => void)(multiple ? media.pick : media.pick[0]),
        }),
    BlockEditorProvider: (props: Props) => {
        blockEditorProps.current = props;
        return <div data-testid="block-editor">{props.children}</div>;
    },
    BlockCanvas: () => <div data-testid="block-canvas" />,
    Inserter: () => <button type="button">Add block</button>,
};

const pluginsApi = { registerPlugin: vi.fn() };

const editorApi = {
    PluginSidebar: ({ title, children }: Props) => (
        <aside aria-label={text(title)}>
            <h1>{title as ReactNode}</h1>
            {children}
        </aside>
    ),
    PluginSidebarMoreMenuItem: ({ children }: Props) => <div data-testid="more-menu-item">{children}</div>,
};

const i18n = {
    __: (value: string) => value,
    sprintf: (format: string, ...args: unknown[]) => {
        let i = 0;
        return format.replace(/%(\d+\$)?[sd]/g, (_match, position?: string) =>
            String(position ? args[Number(position.slice(0, -1)) - 1] : args[i++]),
        );
    },
};

const fakeWp = {
    data,
    components,
    plugins: pluginsApi,
    editor: editorApi,
    i18n,
    element: React,
    blockEditor: blockEditorApi,
    blocks: blocksApi,
    apiFetch,
    // wpautop in miniature: blank lines separate paragraphs.
    autop: {
        autop: (html: string) =>
            html
                .split(/\n\s*\n/)
                .map((part) =>
                    /^\s*<(p|h\d|ul|ol|blockquote|figure|table|div)[\s>]/.test(part) ? part : `<p>${part}</p>`,
                )
                .join('\n'),
        // removep in miniature: paragraphs become blank-line-separated text.
        removep: (html: string) =>
            html
                .replace(/<\/p>\s*<p>/g, '\n\n')
                .replace(/<\/?p>/g, '')
                .trim(),
    },
    htmlEntities: {
        decodeEntities: (value: string) => value.replace(/&amp;/g, '&').replace(/&#8217;/g, '’'),
    },
};

declare global {
    interface Window {
        wp: typeof fakeWp;
    }
}

export function installWpGlobals(): void {
    window.wp = fakeWp;
}

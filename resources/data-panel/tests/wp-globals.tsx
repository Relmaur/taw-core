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

export function resetEditor(): void {
    editor.palette = [];
    editor.settings = {};
    editor.edited = { meta: {} };
    editor.saved = {};
    editPost.mockReset();
}

const coreEditor = {
    getEditorSettings: () => editor.settings,
    getEditedPostAttribute: (key: string) => editor.edited[key],
    getCurrentPostAttribute: (key: string) => editor.saved[key],
};

const blockEditor = {
    getSettings: () => ({ colors: editor.palette }),
};

const data = {
    useSelect: (mapSelect: (select: (store: string) => unknown) => unknown) =>
        mapSelect((store: string) =>
            store === 'core/editor' ? coreEditor : store === 'core/block-editor' ? blockEditor : {},
        ),
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
    TextControl: ({ label, value, onChange, type, disabled, help }: Props) => (
        <label>
            {label as ReactNode}
            <input
                type={text(type) || 'text'}
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
    ToggleControl: ({ label, checked, onChange }: Props) => (
        <label>
            {label as ReactNode}
            <input
                type="checkbox"
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
    Button: ({ children, onClick, disabled }: Props) => (
        <button type="button" disabled={disabled as boolean} onClick={onClick as () => void}>
            {children}
        </button>
    ),
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
        return format.replace(/%(\d+\$)?s/g, (_match, position?: string) =>
            String(position ? args[Number(position.slice(0, -1)) - 1] : args[i++]),
        );
    },
};

const fakeWp = { data, components, plugins: pluginsApi, editor: editorApi, i18n, element: React };

declare global {
    interface Window {
        wp: typeof fakeWp;
    }
}

export function installWpGlobals(): void {
    window.wp = fakeWp;
}

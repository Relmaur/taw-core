/**
 * The card for an edit in progress (component 05, screens 08 and 10): in the panel, never on the page. It names
 * what's being edited, shows the change as a before/after line, and holds Save and Cancel. A rich-text field adds
 * a toolbar with only the formats that field allows, and a row for a link's address.
 */
import type { ComponentChildren } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { fieldName, truncate, typeLabel } from './describe';
import type { EditPlan } from './edit';
import type { FormatState } from './editing';
import { __ } from './i18n';
import { BulletListIcon, LinkIcon, NumberedListIcon, SpinnerIcon } from './icons';
import type { Format } from './rich';

export interface LinkRow {
    text: string;
    url: string;
    /** The caret was on a link already: the button updates it, and it can be removed. */
    existing: boolean;
}

export interface EditCardProps {
    plan: EditPlan;
    /** What the edit shows now, as text, when it differs from before. */
    after: string | null;
    before: string;
    saving: boolean;
    error: string | null;
    /** Template text needs Claude: why Save can't send it now (not connected, Claude busy), or null. */
    blocked: string | null;
    state: FormatState;
    link: LinkRow | null;
    pages: { title: string; url: string }[];
    onSave(): void;
    onCancel(): void;
    onFormat(format: Format | 'ul' | 'ol'): void;
    onLinkOpen(): void;
    onLinkClose(): void;
    onLinkUrl(url: string): void;
    onLinkApply(url: string): void;
}

/** ⌘ on a Mac, Ctrl elsewhere: the shortcuts editing.ts listens for. */
const MOD =
    typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)
        ? '⌘'
        : 'Ctrl+';

/** Toolbar buttons keep the caret on the page: they never take focus from it. */
const keepCaret = (event: MouseEvent) => event.preventDefault();

function Toolbar({
    formats,
    state,
    linkOpen,
    onFormat,
    onLink,
}: {
    formats: Format[];
    state: FormatState;
    linkOpen: boolean;
    onFormat: EditCardProps['onFormat'];
    onLink(): void;
}) {
    const button = (label: string, pressed: boolean, onClick: () => void, icon: ComponentChildren) => (
        <button
            type="button"
            class="tool"
            aria-label={label}
            title={label}
            aria-pressed={pressed}
            onMouseDown={keepCaret}
            onClick={onClick}
        >
            {icon}
        </button>
    );

    return (
        <div class="edit-toolbar" role="toolbar" aria-label={__('Formatting', 'taw-core')}>
            {formats.includes('bold') &&
                button(__('Bold', 'taw-core'), state.bold, () => onFormat('bold'), <span class="tool-b">B</span>)}
            {formats.includes('italic') &&
                button(__('Italic', 'taw-core'), state.italic, () => onFormat('italic'), <span class="tool-i">I</span>)}
            {formats.includes('link') && button(__('Link', 'taw-core'), linkOpen || state.link, onLink, <LinkIcon />)}
            {formats.includes('lists') && (
                <>
                    <span class="tool-rule" aria-hidden="true" />
                    {button(__('Bulleted list', 'taw-core'), state.ul, () => onFormat('ul'), <BulletListIcon />)}
                    {button(__('Numbered list', 'taw-core'), state.ol, () => onFormat('ol'), <NumberedListIcon />)}
                </>
            )}
            <span class="tool-note">{__('Allowed by this field', 'taw-core')}</span>
        </div>
    );
}

function LinkEditor({
    link,
    pages,
    onUrl,
    onApply,
    onClose,
}: {
    link: LinkRow;
    pages: EditCardProps['pages'];
    onUrl(url: string): void;
    onApply(url: string): void;
    onClose(): void;
}) {
    const [url, setUrl] = useState(link.url);
    const input = useRef<HTMLInputElement>(null);
    // The row opens to type the address: focus moves to it (the caret's selection was kept first).
    useEffect(() => input.current?.focus(), []);
    const label = link.text
        ? __('Link for "%s"', 'taw-core').replace('%s', truncate(link.text, 40))
        : __('Link', 'taw-core');

    return (
        <div class="edit-link">
            <label for="lens-link-url" class="edit-link-label">
                {label}
            </label>
            <div class="edit-link-row">
                <input
                    id="lens-link-url"
                    class="edit-link-input mono"
                    type="text"
                    list="lens-link-pages"
                    value={url}
                    placeholder="/about/"
                    autoComplete="off"
                    ref={input}
                    onInput={(event) => {
                        const value = (event.currentTarget as HTMLInputElement).value;
                        setUrl(value);
                        onUrl(value);
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            onApply(url.trim());
                        } else if (event.key === 'Escape') {
                            event.preventDefault();
                            event.stopPropagation();
                            onClose();
                        }
                    }}
                />
                <datalist id="lens-link-pages">
                    {pages.map((page) => (
                        <option key={page.url} value={page.url}>
                            {page.title}
                        </option>
                    ))}
                </datalist>
                <button
                    type="button"
                    class="card-button"
                    aria-disabled={url.trim() === '' ? 'true' : undefined}
                    onClick={() => url.trim() !== '' && onApply(url.trim())}
                >
                    {link.existing ? __('Update link', 'taw-core') : __('Add link', 'taw-core')}
                </button>
                {link.existing && (
                    <button type="button" class="card-button" onClick={() => onApply('')}>
                        {__('Remove', 'taw-core')}
                    </button>
                )}
            </div>
            <span class="edit-hint">{__('Pages on this site are suggested as you type.', 'taw-core')}</span>
        </div>
    );
}

export function EditCard(props: EditCardProps) {
    const { plan, after, before, saving, error, blocked } = props;
    const rich = plan.kind === 'field' && plan.mode === 'rich';
    const title =
        plan.kind === 'field' ? fieldName(plan.source.read, plan.source.path) : `“${truncate(plan.source.text, 48)}”`;
    const badge =
        plan.kind === 'field'
            ? `${__('Field', 'taw-core')} · ${typeLabel(plan.mode === 'rich' ? 'wysiwyg' : (plan.source.read.subs?.[plan.source.path.split('.').pop() ?? '']?.type ?? plan.source.read.type))}`
            : plan.source.how === 'i18n'
              ? __('Template text · translatable', 'taw-core')
              : __('Template text', 'taw-core');
    const explain =
        plan.kind === 'template'
            ? __(
                  'Type on the page. Template text lives in a theme file, so Save sends the change to Claude, and you apply the diff.',
                  'taw-core',
              )
            : __('Type on the page. Saving writes the field value directly; nothing else changes.', 'taw-core');
    const canSave = after !== null && !saving && blocked === null;

    return (
        <section class="card edit" aria-label={__('Editing on the page', 'taw-core')}>
            <div class="card-head">
                <span class={plan.kind === 'field' ? 'card-title mono' : 'card-title'}>{title}</span>
                <span class="card-badge">{badge}</span>
                <span class="edit-status">{__('Editing on the page', 'taw-core')}</span>
            </div>
            <div class="card-location">{explain}</div>
            {rich && (
                <Toolbar
                    formats={plan.formats}
                    state={props.state}
                    linkOpen={props.link !== null}
                    onFormat={props.onFormat}
                    onLink={() => (props.link ? props.onLinkClose() : props.onLinkOpen())}
                />
            )}
            {rich && props.link && (
                <LinkEditor
                    link={props.link}
                    pages={props.pages}
                    onUrl={props.onLinkUrl}
                    onApply={props.onLinkApply}
                    onClose={props.onLinkClose}
                />
            )}
            {after !== null && (
                <div class="edit-change" aria-label={__('Change', 'taw-core')}>
                    <div class="edit-before">
                        <span class="sr-only">{__('Before:', 'taw-core')} </span>
                        {truncate(before, 400)}
                    </div>
                    <div class="edit-after">
                        <span class="sr-only">{__('After:', 'taw-core')} </span>
                        {truncate(after, 400) || __('(empty)', 'taw-core')}
                    </div>
                </div>
            )}
            {rich && (
                <div class="edit-hint edit-note">
                    {__('Saves in TAW’s rich-text format, the same the wp-admin editor writes.', 'taw-core')}
                </div>
            )}
            {(error || blocked) && (
                <div class="edit-error" role={error ? 'alert' : undefined}>
                    {error ?? blocked}
                </div>
            )}
            <div class="card-actions edit-actions">
                <button
                    type="button"
                    class="card-button primary"
                    aria-disabled={canSave ? undefined : 'true'}
                    onMouseDown={keepCaret}
                    onClick={() => canSave && props.onSave()}
                >
                    {saving ? (
                        <>
                            <SpinnerIcon size={12} />
                            {plan.kind === 'template' ? __('Sending…', 'taw-core') : __('Saving…', 'taw-core')}
                        </>
                    ) : (
                        __('Save', 'taw-core')
                    )}
                </button>
                <button type="button" class="card-button" onMouseDown={keepCaret} onClick={props.onCancel}>
                    {__('Cancel', 'taw-core')}
                </button>
                <span class="edit-keys">
                    {rich ? (
                        <>
                            <span class="mono">{MOD}B</span> <span class="mono">{MOD}I</span>{' '}
                            {plan.formats.includes('link') && <span class="mono">{MOD}K</span>}
                        </>
                    ) : (
                        <>
                            <span class="mono">Enter</span> {__('save', 'taw-core')} · <span class="mono">Esc</span>{' '}
                            {__('cancel', 'taw-core')}
                        </>
                    )}
                </span>
            </div>
        </section>
    );
}

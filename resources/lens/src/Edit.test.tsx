import { act, fireEvent, render, screen } from '@testing-library/preact';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { BridgeClient, BridgeHandlers } from './bridge';
import type { BridgeEvent } from './chatModel';
import { startEditing } from './editing';
import type { FieldClient, SaveResult } from './fields';
import { readManifest } from './manifest';
import { onDoubleClick, patchManifest, type PickHandlers } from './page';
import { Panel, type PageEnv, type PanelEnv } from './Panel';
import { DEFAULTS } from './prefs';
import { PageMap } from './sourcemap';
import { $, mountFixture } from './testing';

/** A panel on the fixture page that can edit it, with a fake save route and a bridge the test drives. */
function setup(
    saveResult: (body: unknown) => SaveResult = () => ({ ok: true, saved: '', previous: null, isDefault: false }),
) {
    mountFixture();
    const host = document.createElement('taw-lens');
    document.documentElement.appendChild(host);
    let handlers: BridgeHandlers | null = null;
    let picker: PickHandlers | null = null;
    const send = vi.fn<BridgeClient['send']>(async () => ({ ok: true, status: 202 }));
    const save = vi.fn<FieldClient['save']>(async (body) => saveResult(body));
    const page: PageEnv = {
        map: vi.fn(() => new PageMap(document, readManifest(document)!)),
        pick: vi.fn((h: PickHandlers) => {
            picker = h;
            return () => {};
        }),
        sampler: null,
        onAsset: () => () => {},
        reveal: vi.fn(),
        url: 'https://acme.local/lens-test/',
        editor: 'vscode://file/{path}:{line}',
        edit: (el, mode, h) => startEditing(el as HTMLElement, mode, h),
        onDoubleClick: (callback) => onDoubleClick(document, host, callback),
        patch: (read, path, value, isDefault) => patchManifest(document, read, path, value, isDefault),
    };
    const env: PanelEnv = {
        viewport: () => ({ width: 1440, height: 900, top: 0 }),
        push: vi.fn(),
        theme: vi.fn(),
        save: vi.fn(),
        onResize: () => () => {},
        page,
        fields: { save, pages: vi.fn(async () => [{ title: 'Studio', url: '/studio/' }]) },
        bridge: (h) => {
            handlers = h;
            return { send, close: vi.fn() };
        },
    };
    render(<Panel host="taw.local" initial={DEFAULTS} env={env} />);
    const emit = (...events: BridgeEvent[]) => act(() => events.forEach((e) => handlers!.event(e)));
    const connect = () => {
        act(() => handlers!.status('connected'));
        emit({ type: 'hello', protocol: 1, session: 's1', working: false, busy: null, takenOver: false, history: [] });
    };
    const pick = (selector: string) => {
        fireEvent.click(screen.getByRole('button', { name: 'Pick an element' }));
        act(() => picker!.pick($(selector), false));
        act(() => picker!.stop());
    };
    const typeOnPage = (selector: string, text: string) => {
        const el = $(selector);
        el.textContent = text;
        fireEvent.input(el);
    };

    return { save, send, emit, connect, pick, typeOnPage, picker: () => picker };
}

const flush = () => act(() => new Promise((r) => setTimeout(r, 0)));

// jsdom lays nothing out: give page elements a box so the overlay draws their rings.
beforeEach(() => {
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue(new DOMRect(100, 100, 300, 40));
});
const editHere = () => fireEvent.click(screen.getByRole('button', { name: 'Edit here' }));

describe('editing a text field in place (screen 08)', () => {
    it('Edit here makes the element editable and shows the edit card in the panel', () => {
        const { pick } = setup();
        pick('h1');
        editHere();
        expect($('h1').getAttribute('contenteditable')).toBe('plaintext-only');
        const card = screen.getByRole('region', { name: 'Editing on the page' });
        expect(card).toHaveTextContent('hero_heading');
        expect(card).toHaveTextContent('Field · text');
        expect(card).toHaveTextContent('Saving writes the field value directly; nothing else changes.');
        expect(screen.getByRole('button', { name: 'Save' })).toHaveAttribute('aria-disabled', 'true');
        expect(document.querySelector('.ring[data-state="editing"]')).not.toBeNull();
    });

    it('shows the change as a before/after line, saves it, and says where it went, with Undo', async () => {
        const { pick, typeOnPage, save } = setup((body) => ({
            ok: true,
            saved: (body as { value: string }).value,
            previous: 'Point at anything',
            isDefault: false,
        }));
        pick('h1');
        editHere();
        typeOnPage('h1', 'Point and tell');
        expect(document.querySelector('.edit-before')).toHaveTextContent('Point at anything');
        expect(document.querySelector('.edit-after')).toHaveTextContent('Point and tell');

        fireEvent.keyDown($('h1'), { key: 'Enter' });
        await flush();
        expect(save).toHaveBeenCalledWith({
            store: 'post',
            object: 12,
            key: 'hero_heading',
            value: 'Point and tell',
            expect: 'Point at anything',
        });
        expect($('h1').hasAttribute('contenteditable')).toBe(false);
        expect(screen.queryByRole('region', { name: 'Editing on the page' })).toBeNull();
        expect(document.querySelector('.saved')).toHaveTextContent('Saved to hero_heading');
        // The source map knows the new value: the heading is still the field.
        expect(document.querySelector('.ring-label')).toHaveTextContent('hero › hero_heading');

        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        await flush();
        expect(save).toHaveBeenLastCalledWith({
            store: 'post',
            object: 12,
            key: 'hero_heading',
            value: 'Point at anything',
            expect: 'Point and tell',
        });
        expect($('h1').textContent).toBe('Point at anything');
        expect(document.querySelector('.saved')).toHaveTextContent('Undid the change to hero_heading');
    });

    it('Esc or Cancel puts the text back and saves nothing', () => {
        const { pick, typeOnPage, save } = setup();
        pick('h1');
        editHere();
        typeOnPage('h1', 'Changed');
        fireEvent.keyDown($('h1'), { key: 'Escape' });
        expect($('h1').textContent).toBe('Point at anything');
        expect(save).not.toHaveBeenCalled();

        editHere();
        typeOnPage('h1', 'Changed again');
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect($('h1').textContent).toBe('Point at anything');
    });

    it('keeps editing and says why when the save is refused', async () => {
        const { pick, typeOnPage } = setup(() => ({
            ok: false,
            status: 409,
            error: 'changed',
            message: 'This field changed since the page loaded. Reload the page to edit what’s stored now.',
        }));
        pick('h1');
        editHere();
        typeOnPage('h1', 'Mine');
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await flush();
        expect(screen.getByRole('alert')).toHaveTextContent('This field changed since the page loaded.');
        expect($('h1').getAttribute('contenteditable')).toBe('plaintext-only');
    });

    it('shows what was stored when the field sanitized the value', async () => {
        const { pick, typeOnPage } = setup(() => ({
            ok: true,
            saved: 'Clean',
            previous: 'Point at anything',
            isDefault: false,
        }));
        pick('h1');
        editHere();
        typeOnPage('h1', ' Clean <b> ');
        fireEvent.keyDown($('h1'), { key: 'Enter' });
        await flush();
        expect($('h1').textContent).toBe('Clean');
    });

    it('a double-click on a selected element edits it, and so does one while picking', () => {
        const { pick, picker } = setup();
        pick('h1');
        fireEvent.dblClick($('h1'));
        expect($('h1').getAttribute('contenteditable')).toBe('plaintext-only');
        fireEvent.keyDown($('h1'), { key: 'Escape' });

        fireEvent.click(screen.getByRole('button', { name: 'Pick an element' }));
        act(() => picker()!.edit!($('.faq h3')));
        expect($('.faq h3').getAttribute('contenteditable')).toBe('plaintext-only');
        expect(screen.getByRole('region', { name: 'Editing on the page' })).toHaveTextContent('faq_items[0].question');
    });

    it('says why Edit here can’t edit something on the page', () => {
        const { pick } = setup();
        $('h1').innerHTML = 'Point at <em>anything</em>';
        pick('h1');
        editHere();
        expect(document.querySelector('.card-why')).toHaveTextContent(/Open it in wp-admin/);
        expect($('h1').hasAttribute('contenteditable')).toBe(false);
    });
});

describe('editing a rich-text field (screen 10)', () => {
    it('offers only the field’s formats, and saves in the classic editor’s format', async () => {
        const { pick, save } = setup(() => ({ ok: true, saved: 'x', previous: 'old', isDefault: false }));
        pick('.body p');
        editHere();
        expect($('.body').getAttribute('contenteditable')).toBe('true');
        const toolbar = screen.getByRole('toolbar', { name: 'Formatting' });
        for (const name of ['Bold', 'Italic', 'Link', 'Bulleted list', 'Numbered list']) {
            expect(screen.getByRole('button', { name })).toBeInTheDocument();
        }
        expect(toolbar).toHaveTextContent('Allowed by this field');
        expect(
            screen.getByText('Saves in TAW’s rich-text format, the same the wp-admin editor writes.'),
        ).toBeInTheDocument();

        $('.body').innerHTML =
            '<p><strong>Rich</strong> text, with a <a href="/studio/">link</a>.</p><p>Second paragraph.</p>';
        fireEvent.input($('.body'));
        expect(document.querySelector('.edit-after')).toHaveTextContent('Rich text, with a link. ¶ Second paragraph.');
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await flush();
        expect(save).toHaveBeenCalledWith(
            expect.objectContaining({
                key: 'lens_body',
                value: '<strong>Rich</strong> text, with a <a href="/studio/">link</a>.\n\nSecond paragraph.',
                expect: '<p><strong>Rich</strong> text, with a link.</p>\n<p>Second paragraph.</p>',
            }),
        );
    });

    it('says when only the formatting changed', () => {
        const { pick } = setup();
        pick('.body p');
        editHere();
        $('.body').innerHTML = '<p><strong>Rich</strong> text, with a link.</p><p><b>Second</b> paragraph.</p>';
        fireEvent.input($('.body'));
        expect(document.querySelector('.edit-after')).toHaveTextContent(
            'Rich text, with a link. ¶ Second paragraph. (formatting changed)',
        );
    });

    it('opens a row for the link’s address, with this site’s pages suggested', async () => {
        const { pick } = setup();
        pick('.body p');
        editHere();
        fireEvent.click(screen.getByRole('button', { name: 'Link' }));
        const input = document.getElementById('lens-link-url') as HTMLInputElement;
        expect(input).toHaveFocus();
        expect(screen.getByRole('button', { name: 'Add link' })).toBeInTheDocument();
        expect(screen.getByText('Pages on this site are suggested as you type.')).toBeInTheDocument();
        fireEvent.input(input, { target: { value: 'stu' } });
        await act(() => new Promise((r) => setTimeout(r, 250)));
        expect(document.querySelector('#lens-link-pages option')).toHaveAttribute('value', '/studio/');
        fireEvent.keyDown(input, { key: 'Escape' });
        expect(screen.queryByRole('button', { name: 'Add link' })).toBeNull();
    });
});

describe('editing template text: Claude saves it (screen 09)', () => {
    it('sends the change to Claude as a message from an edit, and shows it that way', async () => {
        const { pick, typeOnPage, send, connect, emit } = setup();
        connect();
        pick('h2');
        editHere();
        expect(screen.getByRole('region', { name: 'Editing on the page' })).toHaveTextContent(
            'Template text lives in a theme file, so Save sends the change to Claude',
        );
        typeOnPage('h2', 'Why Lens');
        fireEvent.keyDown($('h2'), { key: 'Enter' });
        await flush();
        const [path, body] = send.mock.calls[0] as unknown as [string, Record<string, unknown>];
        expect(path).toBe('/message');
        expect(body).toMatchObject({
            text: 'Change "Why TAW Lens" to "Why Lens".',
            chips: ['“Why TAW Lens” · template text'],
            from: 'edit',
        });
        expect(body.context).toContain('<lens-edit file="page.php" line="3">');
        expect($('h2').hasAttribute('contenteditable')).toBe(false);
        // The page keeps the new text, ringed as an edit, while Claude works on it.
        expect($('h2').textContent).toBe('Why Lens');
        expect(document.querySelector('.ring[data-state="editing"] + .ring-label')).toHaveTextContent(
            'Editing · page.php:3',
        );

        emit({ type: 'turn', turn: 1, text: body.text as string, chips: body.chips as string[], from: 'edit' });
        expect(screen.getByText('Sent from an edit on the page')).toBeInTheDocument();
        emit({ type: 'done', turn: 1, outcome: 'ok', message: '', changed: [] });
        // Skipped: the file didn't change, so the page shows the old text again.
        expect($('h2').textContent).toBe('Why TAW Lens');
        expect(document.querySelector('.ring[data-state="editing"]')).toBeNull();
    });

    it('keeps the new text when Claude changed the file', async () => {
        const { pick, typeOnPage, connect, emit } = setup();
        connect();
        pick('h2');
        editHere();
        typeOnPage('h2', 'Why Lens');
        fireEvent.keyDown($('h2'), { key: 'Enter' });
        await flush();
        emit(
            { type: 'turn', turn: 1, text: 'Change…', chips: [], from: 'edit' },
            { type: 'done', turn: 1, outcome: 'ok', message: '', changed: ['page.php'] },
        );
        expect($('h2').textContent).toBe('Why Lens');
    });

    it('can’t send while taw lens isn’t running, and says so', () => {
        const { pick, typeOnPage, send } = setup();
        pick('h2');
        editHere();
        typeOnPage('h2', 'Why Lens');
        expect(screen.getByText('Template text is saved by Claude: start taw lens to send this.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save' })).toHaveAttribute('aria-disabled', 'true');
        expect(send).not.toHaveBeenCalled();
    });
});

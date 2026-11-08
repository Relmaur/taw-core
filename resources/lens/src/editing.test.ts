import { fireEvent } from '@testing-library/preact';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { EDITING_ATTRIBUTE, startEditing } from './editing';

const handlers = () => ({
    change: vi.fn<() => void>(),
    save: vi.fn<() => void>(),
    cancel: vi.fn<() => void>(),
    link: vi.fn<() => void>(),
    select: vi.fn<() => void>(),
});

function page(html = '<h1 class="title">Old title</h1><a class="link" href="/x">Go</a>') {
    document.body.innerHTML = html;
    return document.querySelector<HTMLElement>('.title')!;
}

afterEach(() => {
    document.body.innerHTML = '';
    document.head.innerHTML = '';
});

describe('an edit in progress on the page', () => {
    it('makes only that element editable, marks it, and puts everything back when it stops', () => {
        const el = page();
        const session = startEditing(el, 'text', handlers());
        expect(el.getAttribute('contenteditable')).toBe('plaintext-only');
        expect(el.hasAttribute(EDITING_ATTRIBUTE)).toBe(true);
        expect(document.getElementById('taw-lens-editing')).not.toBeNull();
        expect(document.activeElement).toBe(el);
        // The caret starts at the end of the text itself.
        expect(document.getSelection()!.anchorNode).toBe(el.firstChild);
        expect(document.getSelection()!.anchorOffset).toBe('Old title'.length);

        session.stop();
        expect(el.hasAttribute('contenteditable')).toBe(false);
        expect(el.hasAttribute(EDITING_ATTRIBUTE)).toBe(false);
        expect(document.getElementById('taw-lens-editing')).toBeNull();
    });

    it('removes an empty style attribute the browser’s formatting left, and keeps the element’s own', () => {
        const el = page();
        const session = startEditing(el, 'rich', handlers());
        el.setAttribute('style', '');
        session.stop();
        expect(el.hasAttribute('style')).toBe(false);

        const styled = page('<h1 class="title" style="color: red">Old</h1>');
        startEditing(styled, 'rich', handlers()).stop();
        expect(styled.getAttribute('style')).toBe('color: red');
    });

    it('keeps an element’s own contenteditable', () => {
        const el = page('<h1 class="title" contenteditable="false">Old</h1>');
        startEditing(el, 'rich', handlers()).stop();
        expect(el.getAttribute('contenteditable')).toBe('false');
    });

    it('reads its text, and restore puts back what it showed', () => {
        const el = page();
        const session = startEditing(el, 'text', handlers());
        el.textContent = '  New\n title ';
        expect(session.text()).toBe('New title');
        session.restore();
        expect(el.innerHTML).toBe('Old title');
    });

    it('plain text: Enter saves, Esc cancels, formatting shortcuts do nothing, input reports a change', () => {
        const el = page();
        const h = handlers();
        startEditing(el, 'text', h);
        fireEvent.keyDown(el, { key: 'Enter' });
        expect(h.save).toHaveBeenCalledTimes(1);
        fireEvent.keyDown(el, { key: 'Escape' });
        expect(h.cancel).toHaveBeenCalledTimes(1);
        const bold = new KeyboardEvent('keydown', { key: 'b', ctrlKey: true, metaKey: true, cancelable: true });
        el.dispatchEvent(bold);
        expect(bold.defaultPrevented).toBe(true);
        fireEvent.input(el);
        expect(h.change).toHaveBeenCalled();
    });

    it('plain text: paste inserts the clipboard’s text only, on one line', () => {
        const el = page();
        startEditing(el, 'text', handlers());
        const paste = new Event('paste', { bubbles: true, cancelable: true }) as ClipboardEvent;
        Object.defineProperty(paste, 'clipboardData', {
            value: { getData: (type: string) => (type === 'text/plain' ? 'Pasted\ntext' : '<b>bold</b>') },
        });
        el.dispatchEvent(paste);
        expect(paste.defaultPrevented).toBe(true);
        expect(el.textContent).toContain('Pasted text');
        expect(el.innerHTML).not.toContain('<b>');
    });

    it('rich text: Enter is a new paragraph, ⌘Enter saves, ⌘K opens the link row', () => {
        const el = page();
        const h = handlers();
        startEditing(el, 'rich', h);
        expect(el.getAttribute('contenteditable')).toBe('true');
        fireEvent.keyDown(el, { key: 'Enter' });
        expect(h.save).not.toHaveBeenCalled();
        fireEvent.keyDown(el, { key: 'Enter', metaKey: true, ctrlKey: true });
        expect(h.save).toHaveBeenCalledTimes(1);
        fireEvent.keyDown(el, { key: 'k', metaKey: true, ctrlKey: true });
        expect(h.link).toHaveBeenCalledTimes(1);
    });

    it('links inside the element don’t navigate while it’s edited; others on the page still do', () => {
        const el = page(
            '<p class="title">Read <a class="inner" href="/x">this</a></p><a class="other" href="/y">y</a>',
        );
        const session = startEditing(el, 'rich', handlers());
        const inner = new MouseEvent('click', { bubbles: true, cancelable: true });
        document.querySelector('.inner')!.dispatchEvent(inner);
        expect(inner.defaultPrevented).toBe(true);
        const other = new MouseEvent('click', { bubbles: true, cancelable: true });
        document.querySelector('.other')!.dispatchEvent(other);
        expect(other.defaultPrevented).toBe(false);
        session.stop();
        const after = new MouseEvent('click', { bubbles: true, cancelable: true });
        document.querySelector('.inner')!.dispatchEvent(after);
        expect(after.defaultPrevented).toBe(false);
    });

    it('finds the link at the caret for the link row', () => {
        const el = page('<p class="title">Read <a href="/studio">how we work</a></p>');
        const session = startEditing(el, 'rich', handlers());
        const range = document.createRange();
        range.setStart(el.querySelector('a')!.firstChild!, 2);
        range.collapse(true);
        document.getSelection()!.removeAllRanges();
        document.getSelection()!.addRange(range);
        expect(session.linkUrl()).toBe('/studio');
        expect(session.linkText()).toBe('how we work');
        expect(session.state().link).toBe(true);
        session.link('/about/');
        expect(el.querySelector('a')!.getAttribute('href')).toBe('/about/');
    });
});

/**
 * An edit in progress on the page (component 05): the one element being edited becomes editable, with the
 * browser's own caret. Nothing else on the page changes, and everything is put back when the edit ends.
 *
 * - Plain text: `contenteditable="plaintext-only"` where the browser has it; paste and drop insert plain text
 *   only, and formatting shortcuts do nothing. Enter saves.
 * - Rich text: the browser's editing, limited by the panel's toolbar to the field's formats (rich.ts reads the
 *   result back with only those). ⌘B, ⌘I and ⌘K work on the page; ⌘Enter saves; Enter starts a new paragraph.
 * - Esc cancels. Links in the element don't navigate while it's being edited.
 */
import type { Format } from './rich';

export const EDITING_ATTRIBUTE = 'data-taw-lens-editing';
const STYLE_ID = 'taw-lens-editing';

export interface EditHandlers {
    /** The text or HTML changed. */
    change(): void;
    save(): void;
    cancel(): void;
    /** ⌘K in a rich-text field: the panel opens its link row. */
    link?(): void;
    /** The caret or the selection moved (the toolbar shows what's on). */
    select?(): void;
}

export interface FormatState {
    bold: boolean;
    italic: boolean;
    link: boolean;
    ul: boolean;
    ol: boolean;
}

export interface EditSession {
    readonly el: Element;
    text(): string;
    html(): string;
    /** Puts what the element showed before the edit back. */
    restore(): void;
    /** Ends editing; the element keeps what it shows now. */
    stop(): void;
    focus(): void;
    /** Formatting from the toolbar (rich text only). */
    format(format: Format | 'ul' | 'ol'): void;
    /** Makes the selected text, or the word at the caret, a link; '' removes it. */
    link(url: string): void;
    state(): FormatState;
    /** The text a link would go on: the selection, or the link at the caret. */
    linkText(): string;
    /** The link at the caret's address, if any. */
    linkUrl(): string;
    /** Remembers the selection before the panel takes focus (the link row). */
    keepSelection(): void;
}

const isMac = (): boolean => /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

function placeCaretAtEnd(el: Element): void {
    const doc = el.ownerDocument;
    const selection = doc.getSelection();
    if (!selection) {
        return;
    }
    // Inside the last text, not after the element's last child: there the browser treats End and the arrow keys
    // as page keys and scrolls the page away from the element.
    const walker = doc.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    let last: Text | null = null;
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        if ((node as Text).data.trim() !== '') {
            last = node as Text;
        }
    }
    const range = doc.createRange();
    if (last) {
        range.setStart(last, last.data.replace(/\s+$/, '').length);
        range.collapse(true);
    } else {
        range.selectNodeContents(el);
        range.collapse(false);
    }
    selection.removeAllRanges();
    selection.addRange(range);
}

function insertPlain(doc: Document, text: string): void {
    const clean = text.replace(/[\r\n]+/g, ' ');
    if (!doc.execCommand?.('insertText', false, clean)) {
        const selection = doc.getSelection();
        const range = selection?.rangeCount ? selection.getRangeAt(0) : null;
        if (range) {
            range.deleteContents();
            range.insertNode(doc.createTextNode(clean));
            range.collapse(false);
        }
    }
}

export function startEditing(el: HTMLElement, mode: 'text' | 'rich', handlers: EditHandlers): EditSession {
    const doc = el.ownerDocument;
    const win = doc.defaultView ?? window;
    const before = el.innerHTML;
    const hadEditable = el.getAttribute('contenteditable');
    // The browser's formatting commands can leave an empty style attribute behind: Lens removes what it added.
    const hadStyle = el.hasAttribute('style');
    let kept: Range | null = null;
    let stopped = false;

    // The ring in Lens's overlay shows the edit: the browser's own focus outline would sit on top of it.
    if (!doc.getElementById(STYLE_ID)) {
        const style = doc.createElement('style');
        style.id = STYLE_ID;
        style.textContent = `[${EDITING_ATTRIBUTE}] { outline: none !important; cursor: text !important; }`;
        doc.head.appendChild(style);
    }
    el.setAttribute(EDITING_ATTRIBUTE, '');
    if (mode === 'text') {
        el.setAttribute('contenteditable', 'plaintext-only');
        // A browser without plaintext-only ignores the value: plain `true`, kept plain by the handlers below.
        if ('isContentEditable' in el && !el.isContentEditable) {
            el.setAttribute('contenteditable', 'true');
        }
    } else {
        el.setAttribute('contenteditable', 'true');
        try {
            doc.execCommand?.('defaultParagraphSeparator', false, 'p');
        } catch {
            // Older browsers: the reader turns their <div>s into paragraphs too.
        }
    }

    const modKey = (event: KeyboardEvent) => (isMac() ? event.metaKey : event.ctrlKey);
    const onKey = (event: KeyboardEvent) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            handlers.cancel();
            return;
        }
        if (event.key === 'Enter' && !event.isComposing && (mode === 'text' || modKey(event))) {
            event.preventDefault();
            handlers.save();
            return;
        }
        if (modKey(event) && ['b', 'i', 'u', 'k'].includes(event.key.toLowerCase())) {
            if (mode === 'text' || event.key.toLowerCase() === 'u') {
                event.preventDefault();
            } else if (event.key.toLowerCase() === 'k') {
                event.preventDefault();
                kept = currentRange();
                handlers.link?.();
            }
        }
    };
    const onPaste = (event: ClipboardEvent) => {
        if (mode !== 'text') {
            return;
        }
        event.preventDefault();
        insertPlain(doc, event.clipboardData?.getData('text/plain') ?? '');
    };
    const onDrop = (event: DragEvent) => {
        if (mode === 'text') {
            event.preventDefault();
        }
    };
    const onInput = () => handlers.change();
    const onClick = (event: Event) => {
        if (event.target instanceof Node && el.contains(event.target)) {
            event.preventDefault();
        }
    };
    const onSelection = () => {
        const selection = doc.getSelection();
        if (selection?.anchorNode && el.contains(selection.anchorNode)) {
            handlers.select?.();
        }
    };

    el.addEventListener('keydown', onKey);
    el.addEventListener('paste', onPaste);
    el.addEventListener('drop', onDrop);
    el.addEventListener('input', onInput);
    win.addEventListener('click', onClick, true);
    doc.addEventListener('selectionchange', onSelection);

    const currentRange = (): Range | null => {
        const selection = doc.getSelection();
        const range = selection?.rangeCount ? selection.getRangeAt(0) : null;
        return range && el.contains(range.commonAncestorContainer) ? range.cloneRange() : null;
    };
    const restoreRange = () => {
        el.focus();
        if (kept) {
            const selection = doc.getSelection();
            selection?.removeAllRanges();
            selection?.addRange(kept);
            kept = null;
        }
    };
    const linkAt = (): HTMLAnchorElement | null => {
        const range = kept ?? currentRange();
        let node: Node | null = range?.startContainer ?? null;
        while (node && node !== el) {
            if (node instanceof HTMLAnchorElement) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    };

    const finish = () => {
        if (stopped) {
            return;
        }
        stopped = true;
        el.removeEventListener('keydown', onKey);
        el.removeEventListener('paste', onPaste);
        el.removeEventListener('drop', onDrop);
        el.removeEventListener('input', onInput);
        win.removeEventListener('click', onClick, true);
        doc.removeEventListener('selectionchange', onSelection);
        if (hadEditable === null) {
            el.removeAttribute('contenteditable');
        } else {
            el.setAttribute('contenteditable', hadEditable);
        }
        el.removeAttribute(EDITING_ATTRIBUTE);
        if (!hadStyle && el.getAttribute('style') === '') {
            el.removeAttribute('style');
        }
        if (!doc.querySelector(`[${EDITING_ATTRIBUTE}]`)) {
            doc.getElementById(STYLE_ID)?.remove();
        }
        if (doc.activeElement === el) {
            (el as HTMLElement).blur();
        }
    };

    el.focus();
    placeCaretAtEnd(el);

    return {
        el,
        text: () => (el.textContent ?? '').replace(/\s+/g, ' ').trim(),
        html: () => el.innerHTML,
        restore() {
            el.innerHTML = before;
        },
        stop: finish,
        focus: () => restoreRange(),
        format(format) {
            restoreRange();
            const command =
                format === 'bold'
                    ? 'bold'
                    : format === 'italic'
                      ? 'italic'
                      : format === 'ul'
                        ? 'insertUnorderedList'
                        : format === 'ol'
                          ? 'insertOrderedList'
                          : null;
            if (command) {
                doc.execCommand?.(command, false);
                handlers.change();
            }
        },
        link(url) {
            restoreRange();
            const existing = linkAt();
            if (url === '') {
                if (existing) {
                    const range = doc.createRange();
                    range.selectNodeContents(existing);
                    const selection = doc.getSelection();
                    selection?.removeAllRanges();
                    selection?.addRange(range);
                }
                doc.execCommand?.('unlink', false);
            } else if (existing) {
                existing.setAttribute('href', url);
            } else {
                const selection = doc.getSelection();
                if (selection?.isCollapsed) {
                    selection.modify?.('move', 'backward', 'word');
                    selection.modify?.('extend', 'forward', 'word');
                }
                doc.execCommand?.('createLink', false, url);
            }
            handlers.change();
        },
        state() {
            const query = (command: string) => {
                try {
                    return doc.queryCommandState?.(command) ?? false;
                } catch {
                    return false;
                }
            };
            return {
                bold: query('bold'),
                italic: query('italic'),
                link: linkAt() !== null,
                ul: query('insertUnorderedList'),
                ol: query('insertOrderedList'),
            };
        },
        linkText() {
            const existing = linkAt();
            if (existing) {
                return (existing.textContent ?? '').trim();
            }
            return (kept ?? currentRange())?.toString().trim() ?? '';
        },
        linkUrl: () => linkAt()?.getAttribute('href') ?? '',
        keepSelection() {
            kept = currentRange() ?? kept;
        },
    };
}

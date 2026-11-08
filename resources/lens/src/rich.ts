/**
 * Rich-text fields edited on the page (component 05, screen 10). A wysiwyg value is stored as wp_editor() saves
 * it (taw-core ADR-0007 addendum 1): paragraphs as blank lines, no `<p>`. The page shows it as HTML.
 *
 * Both sides become one form, a list of paragraphs and lists holding only what the field's formats allow
 * (bold, italic, links, lists, line breaks), so Lens can tell:
 * - whether the page shows the stored value faithfully: if reading the value would drop anything (an image, a
 *   heading, a class, a shortcode the page expanded), Lens doesn't edit it on the page;
 * - whether the edit changed anything;
 * - what to store: the edited form, through WordPress's own removep() when the page has it (`wp-autop`), the same
 *   function the classic editor and the data panel save with.
 */

export type Format = 'bold' | 'italic' | 'link' | 'lists';

export type RichBlock = { type: 'p'; html: string } | { type: 'ul' | 'ol'; items: string[] };

export interface Rich {
    blocks: RichBlock[];
    /** Reading it dropped something the formats don't cover. */
    lossy: boolean;
}

const INLINE: Record<string, string> = { STRONG: 'strong', B: 'strong', EM: 'em', I: 'em' };
const LINK_ATTRIBUTES = ['href', 'target', 'rel'];
/** Wrappers a browser adds while editing, unwrapped without loss. */
const UNWRAP = new Set(['SPAN', 'FONT']);
const BLOCKS = new Set(['P', 'DIV']);

const escapeText = (text: string): string => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const escapeAttribute = (text: string): string => escapeText(text).replace(/"/g, '&quot;');

/** Whitespace as a browser shows it: runs collapse to one space. */
const collapse = (text: string): string => text.replace(/[\t\n\r\f ]+/g, ' ');

/** Tidies one paragraph's inline HTML: no space at its edges or around a line break, no empty formatting. */
function tidy(html: string): string {
    let out = html;
    for (let before = ''; before !== out;) {
        before = out;
        out = out.replace(/<(strong|em)>\s*<\/\1>/g, '').replace(/<\/(strong|em)><\1>/g, '');
    }

    return out
        .replace(/ *<br> */g, '<br>')
        .replace(/^(?: |<br>)+|(?: |<br>)+$/g, '')
        .trim();
}

class Reader {
    lossy = false;
    constructor(private readonly formats: ReadonlySet<Format>) {}

    inline(node: Node): string {
        if (node.nodeType === Node.TEXT_NODE) {
            return escapeText(collapse((node as Text).data));
        }
        if (node.nodeType !== Node.ELEMENT_NODE) {
            return '';
        }
        const el = node as Element;
        const inner = () => [...el.childNodes].map((child) => this.inline(child)).join('');
        if (el.tagName === 'BR') {
            return '<br>';
        }
        const tag = INLINE[el.tagName];
        if (tag) {
            if (!this.formats.has(tag === 'strong' ? 'bold' : 'italic') || el.attributes.length > 0) {
                this.lossy = true;
            }
            return this.formats.has(tag === 'strong' ? 'bold' : 'italic') ? `<${tag}>${inner()}</${tag}>` : inner();
        }
        if (el.tagName === 'A' && el.hasAttribute('href')) {
            if (!this.formats.has('link') || [...el.attributes].some((a) => !LINK_ATTRIBUTES.includes(a.name))) {
                this.lossy = true;
            }
            if (!this.formats.has('link')) {
                return inner();
            }
            const attributes = LINK_ATTRIBUTES.filter((name) => el.hasAttribute(name))
                .map((name) => ` ${name}="${escapeAttribute(el.getAttribute(name) ?? '')}"`)
                .join('');
            return `<a${attributes}>${inner()}</a>`;
        }
        if (!UNWRAP.has(el.tagName) || el.attributes.length > 0) {
            this.lossy = true;
        }

        return inner();
    }

    /** Top-level content into blocks: inline runs become paragraphs. */
    blocks(parent: Node): RichBlock[] {
        const out: RichBlock[] = [];
        let run = '';
        const flush = () => {
            const html = tidy(run);
            if (html !== '') {
                out.push({ type: 'p', html });
            }
            run = '';
        };
        for (const child of [...parent.childNodes]) {
            const el = child.nodeType === Node.ELEMENT_NODE ? (child as Element) : null;
            if (el && BLOCKS.has(el.tagName)) {
                flush();
                if (el.attributes.length > 0) {
                    this.lossy = true;
                }
                // A block inside a block (a browser's <div> in a <p>) is its own paragraph.
                out.push(...this.blocks(el));
                continue;
            }
            if (el && (el.tagName === 'UL' || el.tagName === 'OL')) {
                flush();
                if (!this.formats.has('lists') || el.attributes.length > 0) {
                    this.lossy = true;
                }
                const items = [...el.children]
                    .filter((item) => item.tagName === 'LI')
                    .map((item) => tidy([...item.childNodes].map((node) => this.inline(node)).join('')))
                    .filter((item) => item !== '');
                if (el.children.length !== items.length) {
                    this.lossy = true;
                }
                if (this.formats.has('lists')) {
                    if (items.length > 0) {
                        out.push({ type: el.tagName === 'UL' ? 'ul' : 'ol', items });
                    }
                } else {
                    items.forEach((html) => out.push({ type: 'p', html }));
                }
                continue;
            }
            run += this.inline(child);
        }
        flush();

        return out;
    }
}

const fragment = (html: string, doc: Document): DocumentFragment => {
    const template = doc.createElement('template');
    template.innerHTML = html;

    return template.content;
};

/** What the page shows: an element's HTML, as blocks. */
export function readHtml(html: string, formats: readonly Format[], doc: Document = document): Rich {
    const reader = new Reader(new Set(formats));
    const blocks = reader.blocks(fragment(html, doc));

    return { blocks, lossy: reader.lossy };
}

/**
 * A stored value, as blocks: wpautop()'s rule for the parts that matter here (a blank line ends a paragraph, a
 * single line break is a `<br>`) when it holds no paragraphs of its own.
 */
export function readStored(value: string, formats: readonly Format[], doc: Document = document): Rich {
    const html = /<p[\s>]/i.test(value)
        ? value
        : value
              .replace(/\r\n?/g, '\n')
              // A list is a block of its own, on single line breaks as removep() leaves it.
              .replace(/\s*(<(ul|ol)[\s>][\s\S]*?<\/\2>)\s*/gi, '\n\n$1\n\n')
              .split(/\n\s*\n/)
              .map((chunk) => chunk.trim())
              .filter((chunk) => chunk !== '')
              .map((chunk) =>
                  /^<(ul|ol)[\s>]/i.test(chunk)
                      ? chunk.replace(/\s*(<\/?(?:ul|ol|li)(?:\s[^>]*)?>)\s*/gi, '$1')
                      : `<p>${chunk.replace(/\s*\n\s*/g, '<br>')}</p>`,
              )
              .join('');

    return readHtml(html, formats, doc);
}

/** One string per form, for comparing. */
export const sameBlocks = (a: RichBlock[], b: RichBlock[]): boolean => JSON.stringify(a) === JSON.stringify(b);

/** The blocks as HTML with paragraphs, the way an editor serializes them. */
export function blocksHtml(blocks: RichBlock[]): string {
    return blocks
        .map((block) =>
            block.type === 'p'
                ? `<p>${block.html}</p>`
                : `<${block.type}>\n${block.items.map((item) => `<li>${item}</li>`).join('\n')}\n</${block.type}>`,
        )
        .join('\n\n');
}

type Removep = (html: string) => string;

const wpRemovep = (): Removep | undefined =>
    (window as unknown as { wp?: { autop?: { removep?: Removep } } }).wp?.autop?.removep;

/**
 * What to store: the classic editor's format. WordPress's removep() when the page has it; otherwise the same
 * result for these blocks (paragraphs as blank lines, line breaks as newlines).
 */
export function toStored(blocks: RichBlock[], removep: Removep | null = wpRemovep() ?? null): string {
    if (removep) {
        return removep(blocksHtml(blocks)).trim();
    }

    let out = '';
    blocks.forEach((block, i) => {
        if (i > 0) {
            // removep() leaves a list on its own lines, and a blank line between paragraphs.
            out += block.type === 'p' && blocks[i - 1].type === 'p' ? '\n\n' : '\n';
        }
        out +=
            block.type === 'p'
                ? block.html.replace(/<br>/g, '\n')
                : `<${block.type}>\n${block.items.map((item) => ` \t<li>${item.replace(/<br>/g, '\n')}</li>`).join('\n')}\n</${block.type}>`;
    });

    return out;
}

/** Plain text of the blocks, for the card's before/after line. */
export function blocksText(blocks: RichBlock[], doc: Document = document): string {
    return blocks
        .map((block) => {
            const html = block.type === 'p' ? block.html : block.items.join(' · ');
            return (fragment(html.replace(/<br>/g, ' '), doc).textContent ?? '').trim();
        })
        .join(' ¶ ');
}

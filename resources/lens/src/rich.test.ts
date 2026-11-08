import { afterEach, describe, expect, it, vi } from 'vitest';
import { blocksHtml, blocksText, readHtml, readStored, sameBlocks, toStored, type Format } from './rich';

const ALL: Format[] = ['bold', 'italic', 'link', 'lists'];

afterEach(() => {
    delete (window as unknown as { wp?: unknown }).wp;
});

describe('reading what the page shows', () => {
    it('keeps paragraphs, bold, italic, links and line breaks, as the field allows', () => {
        const rich = readHtml(
            '<p>We’re a <b>two-person</b> <i>studio</i>. Read <a href="/studio" target="_blank" rel="noopener">how we work</a>.</p>\n<p>Line<br>two</p>',
            ALL,
        );
        expect(rich.lossy).toBe(false);
        expect(rich.blocks).toEqual([
            {
                type: 'p',
                html: 'We’re a <strong>two-person</strong> <em>studio</em>. Read <a href="/studio" target="_blank" rel="noopener">how we work</a>.',
            },
            { type: 'p', html: 'Line<br>two' },
        ]);
    });

    it('turns what a browser adds while editing into the same blocks: divs, spans, empty formatting', () => {
        const rich = readHtml('Typed <span>here</span><div>New line</div><div><strong> </strong><br></div>', ALL);
        expect(rich.blocks).toEqual([
            { type: 'p', html: 'Typed here' },
            { type: 'p', html: 'New line' },
        ]);
    });

    it('reads lists only when the field allows them', () => {
        expect(readHtml('<ul><li>a</li><li>b <em>c</em></li></ul>', ALL).blocks).toEqual([
            { type: 'ul', items: ['a', 'b <em>c</em>'] },
        ]);
        const noLists = readHtml('<ol><li>a</li></ol>', ['bold']);
        expect(noLists.lossy).toBe(true);
    });

    it('says when reading drops something the formats don’t cover', () => {
        for (const html of [
            '<p>An <img src="/x.jpg"> image</p>',
            '<h2>Heading</h2>',
            '<p class="lead">Styled</p>',
            '<p><a href="/x" class="btn">Button</a></p>',
            '<p><span class="highlight">Marked</span></p>',
        ]) {
            expect(readHtml(html, ALL).lossy, html).toBe(true);
        }
        expect(readHtml('<p><strong>Bold</strong></p>', ['italic']).lossy).toBe(true);
    });
});

describe('a stored value (the classic editor’s format)', () => {
    it('reads blank lines as paragraphs and single line breaks as <br>', () => {
        expect(readStored('One <strong>two</strong>\n\nThree\nfour', ALL).blocks).toEqual([
            { type: 'p', html: 'One <strong>two</strong>' },
            { type: 'p', html: 'Three<br>four' },
        ]);
    });

    it('reads a list on single line breaks, as removep() leaves it', () => {
        expect(readStored('Intro\n<ul>\n \t<li>a</li>\n \t<li>b <em>c</em></li>\n</ul>\nAfter', ALL).blocks).toEqual([
            { type: 'p', html: 'Intro' },
            { type: 'ul', items: ['a', 'b <em>c</em>'] },
            { type: 'p', html: 'After' },
        ]);
    });

    it('reads a value that already has paragraphs as HTML', () => {
        expect(readStored('<p>A</p>\n<p>B</p>', ALL).blocks).toEqual([
            { type: 'p', html: 'A' },
            { type: 'p', html: 'B' },
        ]);
    });

    it('is the same as the page when the page shows it faithfully', () => {
        const stored = readStored('We’re a <strong>studio</strong>.\n\nSecond.', ALL);
        const shown = readHtml('<p>We’re a <strong>studio</strong>.</p>\n<p>Second.</p>', ALL);
        expect(sameBlocks(stored.blocks, shown.blocks)).toBe(true);
    });
});

describe('what Lens stores', () => {
    // Each `stored` is what WordPress's own removep() (wp-includes/js/dist/autop.js) returns for the blocks.
    const cases = [
        ['<p>One <strong>two</strong></p><p>Three<br>four</p>', 'One <strong>two</strong>\n\nThree\nfour'],
        [
            '<p>Intro</p><ul><li>a</li><li>b <em>c</em></li></ul><p>After</p>',
            'Intro\n<ul>\n \t<li>a</li>\n \t<li>b <em>c</em></li>\n</ul>\nAfter',
        ],
        ['<ol><li>x</li></ol>', '<ol>\n \t<li>x</li>\n</ol>'],
        ['<p>We are a <a href="/studio">studio</a>.</p>', 'We are a <a href="/studio">studio</a>.'],
        ['<ul><li>a</li></ul><ul><li>b</li></ul>', '<ul>\n \t<li>a</li>\n</ul>\n<ul>\n \t<li>b</li>\n</ul>'],
    ] as const;

    it.each(cases)('without wp-autop, writes what removep() would: %s', (html, stored) => {
        const blocks = readHtml(html, ALL).blocks;
        expect(toStored(blocks, null)).toBe(stored);
        expect(sameBlocks(readStored(stored, ALL).blocks, blocks)).toBe(true);
    });

    it('uses WordPress’s removep() when the page has it', () => {
        const removep = vi.fn(() => 'from wp ');
        (window as unknown as { wp: unknown }).wp = { autop: { removep } };
        expect(toStored([{ type: 'p', html: 'A' }])).toBe('from wp');
        expect(removep).toHaveBeenCalledWith('<p>A</p>');
    });

    it('serializes blocks with paragraphs, and as text for the before/after line', () => {
        const blocks = readHtml('<p>A <b>b</b></p><ul><li>x</li><li>y</li></ul>', ALL).blocks;
        expect(blocksHtml(blocks)).toBe('<p>A <strong>b</strong></p>\n\n<ul>\n<li>x</li>\n<li>y</li>\n</ul>');
        expect(blocksText(blocks)).toBe('A b ¶ x · y');
    });
});

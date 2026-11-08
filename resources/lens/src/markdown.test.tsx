import { render } from '@testing-library/preact';
import { describe, expect, it } from 'vitest';
import { blocks, Markdown } from './markdown';

const html = (source: string) => render(<Markdown source={source} />).container.innerHTML;

describe('Claude’s replies', () => {
    it('splits paragraphs, lists, headings and code', () => {
        expect(blocks('One\ntwo\n\n- a\n- b\n  more\n\n1. x\n\n## Head\n```css\n.a{}\n```').map((b) => b.kind)).toEqual(
            ['p', 'ul', 'ol', 'h', 'code'],
        );
        expect(blocks('- a\n  more')).toEqual([{ kind: 'ul', items: ['a more'] }]);
        expect(blocks('```\nstill streaming')).toEqual([{ kind: 'code', text: 'still streaming' }]);
    });

    it('renders inline code, bold, emphasis and safe links', () => {
        expect(html('The `hero_title` field, **bold**, *em*, [docs](https://example.com).')).toBe(
            '<div class="md"><p>The <code>hero_title</code> field, <strong>bold</strong>, <em>em</em>, <a href="https://example.com" target="_blank" rel="noopener noreferrer">docs</a>.</p></div>',
        );
    });

    it('never renders markup from a reply, and never a script link', () => {
        const out = html('<img src=x onerror=alert(1)> [x](javascript:alert(1))');
        expect(out).not.toContain('<img');
        expect(out).toContain('&lt;img');
        expect(out).not.toContain('href');
    });

    it('leaves snake_case alone', () => {
        expect(html('hero_title and foo_bar_baz')).toBe('<div class="md"><p>hero_title and foo_bar_baz</p></div>');
    });
});

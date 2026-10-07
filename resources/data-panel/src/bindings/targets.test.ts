import { describe, expect, it } from 'vitest';
import type { BindingArgs } from './logic';
import { expressionTargets, opensNewTab, withExpression } from './targets';

const SOURCE = 'taw/field';
/** Binding args as stored (expression bindings have no `field`). */
const stored = (args: object) => args as unknown as BindingArgs;

describe('expression targets (ADR-0015)', () => {
    it('offers a button its text, link, new tab and rel, an image itself, its alt, caption and title', () => {
        expect(expressionTargets('core/button').map((t) => t.key)).toStrictEqual(['text', 'link', 'newTab', 'rel']);
        expect(expressionTargets('core/image')).toStrictEqual([
            { key: 'image', label: 'Image', attributes: ['id', 'url', 'alt'], as: 'image' },
            { key: 'alt', label: 'Alt text', attributes: ['alt'] },
            { key: 'caption', label: 'Caption', attributes: ['caption'] },
            { key: 'title', label: 'Title', attributes: ['title'] },
        ]);
        expect(expressionTargets('core/paragraph')).toStrictEqual([
            { key: 'text', label: 'Text', attributes: ['content'] },
        ]);
        expect(expressionTargets('core/cover')).toStrictEqual([]);
    });

    it('offers navigation links their URL and the post date its date (ADR-0016)', () => {
        expect(expressionTargets('core/navigation-link')).toStrictEqual([
            { key: 'link', label: 'Link', attributes: ['url'] },
        ]);
        expect(expressionTargets('core/navigation-submenu').map((t) => t.attributes)).toStrictEqual([['url']]);
        expect(expressionTargets('core/post-date')).toStrictEqual([
            { key: 'date', label: 'Date', attributes: ['datetime'] },
        ]);
    });

    it('binds new tab and rel without touching the button’s text and link', () => {
        const text = { source: SOURCE, args: stored({ expr: 'Buy' }) };
        const url = { source: SOURCE, args: stored({ expr: '@book_buy_link' }) };
        const [, , newTab, rel] = expressionTargets('core/button');
        let metadata = withExpression(SOURCE, { bindings: { text, url } }, newTab, "@(@book_buy_link != '')");
        metadata = withExpression(SOURCE, metadata, rel, 'sponsored');
        expect(metadata.bindings).toStrictEqual({
            text,
            url,
            linkTarget: { source: SOURCE, args: { expr: "@(@book_buy_link != '')" } },
            rel: { source: SOURCE, args: { expr: 'sponsored' } },
        });
    });

    it('reads a new-tab result as the server does', () => {
        expect(opensNewTab('1')).toBe(true);
        expect(opensNewTab('yes')).toBe(true);
        expect(opensNewTab(' 0 ')).toBe(false);
        expect(opensNewTab('')).toBe(false);
    });

    it('binds a link and keeps the button text binding', () => {
        const text = { source: SOURCE, args: stored({ expr: 'Buy @post.title' }) };
        const [, link] = expressionTargets('core/button');
        const next = withExpression(SOURCE, { bindings: { text } }, link, '@book_buy_link');
        expect(next.bindings).toStrictEqual({ text, url: { source: SOURCE, args: { expr: '@book_buy_link' } } });
    });

    it('binds an image as a whole, with its size-free args', () => {
        const [image] = expressionTargets('core/image');
        const next = withExpression(
            SOURCE,
            { bindings: { caption: { source: 'core/post-meta', args: stored({ key: 'x' }) } } },
            image,
            '@coalesce(@book_cover, 50)',
        );
        const args = { expr: '@coalesce(@book_cover, 50)', as: 'image' };
        expect(next.bindings).toStrictEqual({
            caption: { source: 'core/post-meta', args: { key: 'x' } },
            id: { source: SOURCE, args },
            url: { source: SOURCE, args },
            alt: { source: SOURCE, args },
        });
    });

    it('binds text as before: the block’s other TAW bindings go, conditions come along', () => {
        const next = withExpression(
            SOURCE,
            { bindings: { content: { source: SOURCE, args: stored({ field: 'book_year' }) } }, name: 'kept' },
            'content',
            '@book_year',
            { if: { match: 'all', rules: [] }, else: 'n/a' },
        );
        expect(next).toStrictEqual({
            name: 'kept',
            bindings: {
                content: { source: SOURCE, args: { expr: '@book_year', if: { match: 'all', rules: [] }, else: 'n/a' } },
            },
        });
    });
});

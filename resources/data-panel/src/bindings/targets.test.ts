import { describe, expect, it } from 'vitest';
import type { BindingArgs } from './logic';
import { expressionTargets, withExpression } from './targets';

const SOURCE = 'taw/field';
/** Binding args as stored (expression bindings have no `field`). */
const stored = (args: object) => args as unknown as BindingArgs;

describe('expression targets (ADR-0015)', () => {
    it('offers a button its text and link, an image itself and its alt', () => {
        expect(expressionTargets('core/button').map((t) => t.key)).toStrictEqual(['text', 'link']);
        expect(expressionTargets('core/image')).toStrictEqual([
            { key: 'image', label: 'Image', attributes: ['id', 'url', 'alt'], as: 'image' },
            { key: 'alt', label: 'Alt text', attributes: ['alt'] },
        ]);
        expect(expressionTargets('core/paragraph')).toStrictEqual([
            { key: 'text', label: 'Text', attributes: ['content'] },
        ]);
        expect(expressionTargets('core/cover')).toStrictEqual([]);
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

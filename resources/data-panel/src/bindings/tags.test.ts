import { describe, expect, it } from 'vitest';
import type { Config } from './logic';
import {
    chipObject,
    functionSuggestions,
    groupOptions,
    nameSuggestions,
    parseTag,
    serializeTag,
    storedText,
    toExpression,
    valueOptions,
} from './tags';

const config: Config = {
    source: 'taw/field',
    route: '/taw/v1/bindings/preview',
    fields: {
        post: {
            book: [
                {
                    label: 'Year',
                    args: { field: 'book_year', from: 'post' },
                    type: 'string',
                    fieldType: 'number',
                    fieldset: 'Book details',
                },
                {
                    label: 'Released',
                    args: { field: 'book_released', from: 'post' },
                    type: 'string',
                    fieldType: 'datepicker',
                    fieldset: 'Book details',
                },
                {
                    label: 'Cover',
                    args: { field: 'book_cover', from: 'post' },
                    type: 'string',
                    fieldType: 'image',
                    fieldset: 'Book details',
                },
                {
                    label: 'Cover (image ID)',
                    args: { field: 'book_cover', from: 'post' },
                    type: 'number',
                    fieldType: 'image',
                    fieldset: 'Book details',
                },
                {
                    label: 'Address › City',
                    args: { field: 'address', from: 'post', sub: 'city' },
                    type: 'string',
                    fieldType: 'text',
                    fieldset: 'Where',
                },
            ],
        },
        option: [
            {
                label: 'Phone',
                args: { field: 'company_phone', from: 'option' },
                type: 'string',
                fieldType: 'text',
                fieldset: 'Site settings',
            },
        ],
        term: [],
        user: [{ label: 'Bio', args: { field: 'bio', from: 'user' }, type: 'string', fieldType: 'text' }],
    },
};

describe('the TAW data popup values', () => {
    const options = valueOptions(config, 'book');

    it('offers fields by fieldset, then post and site properties, with expression names', () => {
        expect(groupOptions(options, '').map(([group, items]) => [group, items.map((o) => o.name)])).toStrictEqual([
            ['Book details', ['book_year', 'book_released', 'book_cover']],
            ['Where', ['address_city']],
            ['Site settings', ['option.company_phone']],
            ['Author', ['author.bio']],
            [
                'Post',
                [
                    'post.title',
                    'post.date',
                    'post.modified',
                    'post.author',
                    'post.excerpt',
                    'post.url',
                    'post.id',
                    'post.type',
                ],
            ],
            ['Site', ['site.name', 'site.tagline', 'site.url', 'site.year']],
        ]);
        expect(options.find((o) => o.name === 'book_released')?.isDate).toBe(true);
    });

    it('searches labels, names and groups', () => {
        expect(groupOptions(options, 'phone').map(([g]) => g)).toStrictEqual(['Site settings']);
        expect(groupOptions(options, 'book details')[0][1]).toHaveLength(3);
    });

    it('suggests names by prefix first, then by contains', () => {
        expect(nameSuggestions(options, 'post.t').map((o) => o.name)).toStrictEqual(['post.title', 'post.type']);
        expect(nameSuggestions(options, 'year').map((o) => o.name)).toStrictEqual(['book_year', 'site.year']);
    });

    it('suggests functions with starter arguments', () => {
        expect(functionSuggestions('')).toStrictEqual([
            { fn: 'format', insert: "format('F j, Y')" },
            { fn: 'upper', insert: 'upper()' },
            { fn: 'lower', insert: 'lower()' },
            { fn: 'default', insert: "default('')" },
            { fn: 'truncate', insert: 'truncate(20)' },
        ]);
    });
});

describe('chips', () => {
    it('serializes and parses data-taw-tag with a stable key order', () => {
        expect(serializeTag({ from: 'post', field: 'book_year', fallback: '—', format: ' ' })).toBe(
            '{"field":"book_year","from":"post","fallback":"—"}',
        );
        expect(serializeTag({ expr: 'By @post.author', format: 'Y' })).toBe('{"expr":"By @post.author"}');
        expect(parseTag('{"tag":"post.date","format":"Y"}')).toStrictEqual({ tag: 'post.date', format: 'Y' });
        expect(parseTag('{"nope":1}')).toBeNull();
        expect(parseTag('not json')).toBeNull();
    });

    it('turns a single-value chip into an expression to edit', () => {
        const options = valueOptions(config, 'book');
        expect(toExpression({ tag: 'post.date', format: 'F j, Y' }, options)).toBe("@post.date.format('F j, Y')");
        expect(toExpression({ field: 'company_phone', from: 'option', fallback: "it's" }, options)).toBe(
            "@option.company_phone.default('it\\'s')",
        );
        expect(toExpression({ field: 'address', from: 'post', sub: 'city' }, options)).toBe('@address_city');
        expect(toExpression({ expr: 'Hi @post.title' }, options)).toBe('Hi @post.title');
    });

    it('builds the rich-text object with escaped text, bracketed when empty', () => {
        expect(chipObject({ tag: 'post.title' }, storedText('A <b> & c', 'Title'))).toStrictEqual({
            type: 'taw/tag',
            attributes: { 'data-taw-tag': '{"tag":"post.title"}' },
            innerHTML: 'A &lt;b&gt; &amp; c',
        });
        expect(storedText('', 'Subtitle')).toBe('[Subtitle]');
    });
});

import { describe, expect, it, vi } from 'vitest';
import {
    batcher,
    candidatesFor,
    fieldsList,
    placeholder,
    planFor,
    previewItem,
    source,
    STORE,
    withoutTawBindings,
    connectedMetadata,
    connectedArgs,
    sameField,
    awaitingField,
    boundVariation,
    isBound,
    newlyUnbound,
    unboundCounts,
    type BlockNode,
    type Config,
} from './logic';

const config: Config = {
    source: 'taw/field',
    route: '/taw/v1/bindings/preview',
    fields: {
        post: {
            book: [
                { label: 'Subtitle', args: { field: 'subtitle', from: 'post' }, type: 'string' },
                { label: 'Cover', args: { field: 'cover', from: 'post' }, type: 'string' },
                { label: 'Cover (image ID)', args: { field: 'cover', from: 'post' }, type: 'number' },
            ],
            page: [{ label: 'Subtitle', args: { field: 'subtitle', from: 'post' }, type: 'string' }],
        },
        option: [{ label: 'Phone', args: { field: 'company_phone', from: 'option' }, type: 'string' }],
        term: [{ label: 'Tagline', args: { field: 'tagline', from: 'term' }, type: 'string' }],
        user: [{ label: 'Social › Site', args: { field: 'social', sub: 'site', from: 'user' }, type: 'string' }],
    },
};

describe('taw/field in the editor', () => {
    it('keys a preview by block, attribute, post and args (options by args only)', () => {
        const a = previewItem({ field: 'subtitle' }, 'core/heading', 'content', { postId: 5, postType: 'book' });
        expect(a).toMatchObject({
            args: { field: 'subtitle', from: 'post' },
            block: 'core/heading',
            postId: 5,
            postType: 'book',
        });
        expect(
            previewItem({ field: 'subtitle', from: 'post' }, 'core/heading', 'content', { postId: 5, postType: 'book' })
                .key,
        ).toBe(a.key);
        expect(
            previewItem({ field: 'subtitle' }, 'core/heading', 'content', { postId: 6, postType: 'book' }).key,
        ).not.toBe(a.key);
        expect(
            previewItem({ field: 'company_phone', from: 'option' }, 'core/paragraph', 'content', { postId: 5 }).key,
        ).toBe(previewItem({ field: 'company_phone', from: 'option' }, 'core/paragraph', 'content', { postId: 9 }).key);
    });

    it("lists the post type's fields, then options, term and author, labelled", () => {
        const labels = fieldsList(config, { postType: 'book' }).map((f) => f.label);
        expect(labels).toEqual([
            'Post: Subtitle',
            'Post: Cover',
            'Post: Cover (image ID)',
            'Options: Phone',
            'Term: Tagline',
            'Author: Social › Site',
        ]);
        expect(fieldsList(config, {}).filter((f) => f.label === 'Post: Subtitle')).toHaveLength(1);
    });

    it('shows the field label while loading or when empty, for text attributes only', () => {
        expect(placeholder(config, { field: 'subtitle' }, 'content')).toBe('Post: Subtitle');
        expect(placeholder(config, { field: 'social', sub: 'site', from: 'user' }, 'text')).toBe(
            'Author: Social › Site',
        );
        expect(placeholder(config, { field: 'cover' }, 'url')).toBeUndefined();
        expect(placeholder(config, { field: 'gone' }, 'content')).toBe('Post: gone');
    });

    it('reads previews from the store, falling back to the label', () => {
        const getValue = vi.fn((item: { attribute: string }) => (item.attribute === 'url' ? '/buy' : undefined));
        const select = (store: string) => (store === STORE ? { getValue } : { getBlockName: () => 'core/button' });
        const values = source(config).getValues({
            select,
            context: { postId: 5, postType: 'book' },
            clientId: 'c1',
            bindings: { url: { args: { field: 'cta' } }, text: { args: { field: 'subtitle' } } },
        });
        expect(values).toEqual({ url: '/buy', text: 'Post: Subtitle' });
        expect(getValue).toHaveBeenCalledWith(
            expect.objectContaining({ block: 'core/button', attribute: 'url', postId: 5 }),
        );
        expect(source(config)).not.toHaveProperty('setValues');
    });

    it('sends previews asked in the same tick as one request', async () => {
        const send = vi.fn(async (items: { key: string }[]) =>
            Object.fromEntries(items.map((i) => [i.key, `v:${i.key}`])),
        );
        const fetchValue = batcher(send);
        const a = previewItem({ field: 'subtitle' }, 'core/heading', 'content', { postId: 5 });
        const b = previewItem({ field: 'cover' }, 'core/image', 'url', { postId: 5 });
        await expect(Promise.all([fetchValue(a), fetchValue(b)])).resolves.toEqual([`v:${a.key}`, `v:${b.key}`]);
        expect(send).toHaveBeenCalledTimes(1);
    });

    it('plans the attributes one pick binds, per block', () => {
        const entry = (fieldType: string, type: 'string' | 'number' = 'string') => ({
            label: 'x',
            args: { field: 'f' },
            type,
            fieldType,
        });
        const attrs = (block: string, fieldType: string) =>
            Object.keys(planFor('taw/field', block, entry(fieldType)) ?? {});
        expect(attrs('core/heading', 'text')).toEqual(['content']);
        expect(attrs('core/paragraph', 'wysiwyg')).toEqual(['content']);
        expect(attrs('core/paragraph', 'image')).toEqual([]);
        expect(attrs('core/button', 'link')).toEqual(['url', 'text', 'linkTarget', 'rel']);
        expect(attrs('core/button', 'url')).toEqual(['url']);
        expect(attrs('core/button', 'text')).toEqual(['text']);
        expect(attrs('core/image', 'image')).toEqual(['id', 'url', 'alt']);
        expect(attrs('core/image', 'text')).toEqual([]);
        expect(attrs('core/post-date', 'datepicker')).toEqual(['datetime']);
        expect(planFor('taw/field', 'core/image', entry('image', 'number'))).toBeNull();
        expect(planFor('taw/field', 'core/heading', entry('text'))).toEqual({
            content: { source: 'taw/field', args: { field: 'f' } },
        });
    });

    it('offers only fitting fields, and disconnects only its own bindings', () => {
        const withTypes: Config = {
            ...config,
            fields: {
                ...config.fields,
                post: {
                    book: [
                        {
                            label: 'Subtitle',
                            args: { field: 'subtitle', from: 'post' },
                            type: 'string',
                            fieldType: 'text',
                        },
                        { label: 'Cover', args: { field: 'cover', from: 'post' }, type: 'string', fieldType: 'image' },
                        {
                            label: 'Cover (image ID)',
                            args: { field: 'cover', from: 'post' },
                            type: 'number',
                            fieldType: 'image',
                        },
                    ],
                },
                option: [
                    {
                        label: 'Phone',
                        args: { field: 'company_phone', from: 'option' },
                        type: 'string',
                        fieldType: 'text',
                    },
                ],
                term: [],
                user: [],
            },
        };
        expect(candidatesFor(withTypes, 'core/image', { postType: 'book' }).map((e) => e.label)).toEqual([
            'Post: Cover',
        ]);
        expect(candidatesFor(withTypes, 'core/heading', { postType: 'book' }).map((e) => e.label)).toEqual([
            'Post: Subtitle',
            'Options: Phone',
        ]);

        const bindings = {
            content: { source: 'taw/field', args: { field: 'subtitle' } },
            url: { source: 'core/post-meta', args: { field: 'k' } },
        };
        expect(withoutTawBindings('taw/field', bindings)).toEqual({ url: bindings.url });
        expect(withoutTawBindings('taw/field', { content: bindings.content })).toBeUndefined();
    });

    it('connecting replaces only TAW bindings, and knows which field is connected', () => {
        const link = {
            label: 'Buy',
            args: { field: 'cta', from: 'post' as const },
            type: 'string' as const,
            fieldType: 'link',
        };
        const metadata = {
            name: 'Hero CTA',
            bindings: {
                url: { source: 'taw/field', args: { field: 'old' } },
                rel: { source: 'other/src', args: { field: 'r' } },
            },
        };
        const next = connectedMetadata('taw/field', 'core/button', metadata, link);
        expect(next?.name).toBe('Hero CTA');
        expect(Object.keys(next?.bindings ?? {}).sort()).toEqual(['linkTarget', 'rel', 'text', 'url']);
        expect(next?.bindings?.url.args.field).toBe('cta');
        expect(connectedMetadata('taw/field', 'core/image', metadata, link)).toBeNull();

        expect(connectedArgs('taw/field', next?.bindings)).toEqual({ field: 'cta', from: 'post' });
        expect(connectedArgs('taw/field', { rel: { source: 'other/src', args: { field: 'r' } } })).toBeNull();
        expect(sameField({ field: 'cta' }, { field: 'cta', from: 'post' })).toBe(true);
        expect(
            sameField({ field: 'social', sub: 'x', from: 'option' }, { field: 'social', sub: 'y', from: 'option' }),
        ).toBe(false);
        expect(sameField(null, { field: 'cta' })).toBe(false);
    });

    it('returns the same objects for the same results (WordPress calls these in useSelect)', () => {
        const src = source(config);
        expect(src.getFieldsList({ context: { postType: 'book' } })).toBe(
            src.getFieldsList({ context: { postType: 'book' } }),
        );
        const select = (store: string) =>
            store === STORE ? { getValue: () => 'x' } : { getBlockName: () => 'core/heading' };
        const args = {
            select,
            context: { postId: 5 },
            clientId: 'c1',
            bindings: { content: { args: { field: 'subtitle' } } },
        };
        expect(src.getValues(args)).toBe(src.getValues(args));
    });
});

describe('allowBound in the editor', () => {
    const bound = (field: string) => ({ content: { source: 'taw/field', args: { field } } });

    it('is bound only with a field picked, and awaits one otherwise', () => {
        expect(isBound('taw/field', bound('headline'))).toBe(true);
        expect(isBound('taw/field', bound(' '))).toBe(false);
        expect(isBound('taw/field', { content: { source: 'core/post-meta', args: { field: 'x' } } })).toBe(false);
        expect(awaitingField('taw/field', bound(''))).toBe(true);
        expect(awaitingField('taw/field', bound('headline'))).toBe(false);
        expect(awaitingField('taw/field', undefined)).toBe(false);
    });

    it('offers a default inserter variation bound to no field yet', () => {
        const variation = boundVariation('taw/field', 'core/button')!;
        expect(variation).toMatchObject({
            name: 'taw-field',
            title: 'Field button',
            isDefault: true,
            scope: ['inserter'],
            attributes: { metadata: { bindings: { text: { source: 'taw/field', args: { field: '' } } } } },
        });
        const isActive = variation.isActive as (a: unknown) => boolean;
        expect(isActive({ metadata: { bindings: bound('cta') } })).toBe(true);
        expect(isActive({})).toBe(false);
        expect(boundVariation('taw/field', 'core/columns')).toBeNull();
    });

    it('counts unbound blocks like the server and finds the ones an edit adds', () => {
        const blocks: BlockNode[] = [
            { name: 'core/paragraph', attributes: { metadata: { bindings: bound('a') } } },
            { name: 'core/paragraph', attributes: {} },
            {
                name: 'core/group',
                attributes: {},
                innerBlocks: [{ name: 'core/paragraph', attributes: { metadata: { bindings: bound('') } } }],
            },
        ];
        const edited = unboundCounts('taw/field', blocks);
        expect(edited).toEqual({ 'core/paragraph': 2, 'core/group': 1 });
        expect(newlyUnbound(['core/paragraph', 'core/image'], { 'core/paragraph': 1 }, edited)).toEqual([
            'core/paragraph',
        ]);
        expect(newlyUnbound(['core/paragraph'], { 'core/paragraph': 2 }, edited)).toEqual([]);
    });

    it('shows a prompt instead of asking the server when no field is picked', () => {
        const getValue = vi.fn();
        const select = (store: string) => (store === STORE ? { getValue } : { getBlockName: () => 'core/paragraph' });
        const values = source(config).getValues({ select, context: {}, bindings: bound(''), clientId: 'c1' });
        expect(values).toEqual({ content: 'Choose a TAW field' });
        expect(getValue).not.toHaveBeenCalled();
        expect(placeholder(config, { field: '' }, 'url')).toBeUndefined();
    });

    it('keeps one result per field when the Attributes panel asks without a clientId', () => {
        const values: Record<string, string> = { headline: 'H', intro: 'I' };
        const select = (store: string) =>
            store === STORE
                ? { getValue: (item: { args: { field: string } }) => values[item.args.field] }
                : { getBlockName: () => null };
        const src = source(config);
        const ask = (field: string) =>
            src.getValues({ select, context: { postId: 5, postType: 'book' }, bindings: bound(field) });
        const headline = ask('headline');
        const intro = ask('intro');
        expect(headline).toEqual({ content: 'H' });
        expect(intro).toEqual({ content: 'I' });
        expect(ask('headline')).toBe(headline);
        expect(ask('intro')).toBe(intro);
    });
});

import { describe, expect, it, vi } from 'vitest';
import { batcher, fieldsList, placeholder, previewItem, source, STORE, type Config } from './logic';

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
});

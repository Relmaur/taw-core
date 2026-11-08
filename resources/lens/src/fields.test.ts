import { describe, expect, it, vi } from 'vitest';
import { fieldClient, relative } from './fields';
import { patchManifest } from './page';
import { readManifest } from './manifest';
import { mountFixture } from './testing';

const config = {
    field: 'https://acme.local/wp-json/taw/v1/lens/field',
    search: 'https://acme.local/wp-json/wp/v2/search',
    nonce: 'n1',
    home: 'https://acme.local',
};
const reply = (status: number, body: unknown) =>
    Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } }));
const request = { store: 'post' as const, object: 12, key: 'hero_heading', value: 'New', expect: 'Old' };

describe('saving a field from the page', () => {
    it('posts the edit with the REST nonce and reads what was stored and how to undo it', async () => {
        const fetcher = vi.fn(() => reply(200, { saved: 'New', previous: 'Old', default: false }));
        const result = await fieldClient(config, fetcher).save(request);
        expect(result).toEqual({ ok: true, saved: 'New', previous: 'Old', isDefault: false });
        const [url, init] = fetcher.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe(config.field);
        expect(init.method).toBe('POST');
        expect(init.credentials).toBe('same-origin');
        expect((init.headers as Record<string, string>)['x-wp-nonce']).toBe('n1');
        expect(JSON.parse(init.body as string)).toEqual(request);
    });

    it('a save over a default can be undone back to "nothing stored"', async () => {
        const result = await fieldClient(config, () =>
            reply(200, { saved: 'New', previous: null, default: false }),
        ).save(request);
        expect(result).toMatchObject({ ok: true, previous: null });
    });

    it('passes on what the server said when it refused, and says so when it can’t be reached', async () => {
        const conflict = await fieldClient(config, () =>
            reply(409, { error: 'changed', message: 'This field changed since the page loaded.' }),
        ).save(request);
        expect(conflict).toEqual({
            ok: false,
            status: 409,
            error: 'changed',
            message: 'This field changed since the page loaded.',
        });
        const forbidden = await fieldClient(config, () => reply(403, { code: 'rest_cookie_invalid_nonce' })).save(
            request,
        );
        expect(forbidden).toMatchObject({ ok: false, status: 403, message: expect.stringMatching(/Reload the page/) });
        const offline = await fieldClient(config, () => Promise.reject(new Error('down'))).save(request);
        expect(offline).toMatchObject({ ok: false, status: 0, error: 'network' });
    });
});

describe('suggesting this site’s pages for a link', () => {
    it('searches WordPress and gives site-relative addresses', async () => {
        const fetcher = vi.fn(() =>
            reply(200, [
                { title: 'Studio', url: 'https://acme.local/studio/' },
                { title: 'Elsewhere', url: 'https://other.example/x' },
            ]),
        );
        expect(await fieldClient(config, fetcher).pages('/stu')).toEqual([
            { title: 'Studio', url: '/studio/' },
            { title: 'Elsewhere', url: 'https://other.example/x' },
        ]);
        const [url] = fetcher.mock.calls[0] as unknown as [string];
        expect(url).toContain('search=stu');
        expect(url).toContain('type=post');
    });

    it('doesn’t search for an address that is already a full link', async () => {
        const fetcher = vi.fn(() => reply(200, []));
        expect(await fieldClient(config, fetcher).pages('https://x.y')).toEqual([]);
        expect(await fieldClient(config, fetcher).pages('  ')).toEqual([]);
        expect(fetcher).not.toHaveBeenCalled();
    });

    it('keeps other sites’ addresses whole', () => {
        expect(relative('https://acme.local/a/?b=1#c', 'https://acme.local')).toBe('/a/?b=1#c');
        expect(relative('not a url', 'https://acme.local')).toBe('not a url');
    });
});

describe('after a save, the source map knows the new value', () => {
    it('changes the read, and a repeater row inside its rows', () => {
        mountFixture();
        patchManifest(document, { store: 'post', object: 12, key: 'hero_heading' }, 'hero_heading', 'New', false);
        patchManifest(document, { store: 'post', object: 12, key: 'faq_items' }, 'faq_items[0].answer', 'Yes.', false);
        patchManifest(
            document,
            { store: 'option', object: 'taw-settings', key: 'company_phone' },
            'company_phone',
            '1',
            false,
        );
        const manifest = readManifest(document)!;
        expect(manifest.nodes.get(2)!.reads[0].value).toBe('New');
        expect(JSON.parse(manifest.nodes.get(4)!.reads[0].value as string)).toEqual([
            { question: 'What is Lens?', answer: 'Yes.' },
        ]);
        const phone = manifest.nodes.get(0)!.reads.find((r) => r.key === 'company_phone')!;
        expect(phone.value).toBe('1');
        expect(phone.default).toBeUndefined();
    });
});

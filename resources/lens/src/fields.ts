/**
 * Saving a field edited on the page: `POST taw/v1/lens/field` (TAW\Core\Lens\FieldWriter), with the REST nonce.
 * It needs no bridge: field edits work while `taw lens` isn't running. Link suggestions come from WordPress's
 * own search route.
 */
import type { FieldRequest } from './edit';
import { __ } from './i18n';

export type SaveResult =
    | { ok: true; saved: string; previous: string | null; isDefault: boolean }
    | { ok: false; status: number; error: string; message: string };

export interface PageLink {
    title: string;
    url: string;
}

export interface FieldClient {
    save(request: FieldRequest): Promise<SaveResult>;
    /** This site's pages and posts matching `query`, as site-relative addresses. */
    pages(query: string): Promise<PageLink[]>;
}

type Fetch = (input: string, init?: RequestInit) => Promise<Response>;

export function fieldClient(
    config: { field: string; search: string; nonce: string; home: string },
    fetcher: Fetch = (input, init) => fetch(input, init),
): FieldClient {
    return {
        async save(request) {
            try {
                const response = await fetcher(config.field, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'content-type': 'application/json', 'x-wp-nonce': config.nonce },
                    body: JSON.stringify(request),
                });
                const body = (await response.json().catch(() => ({}))) as Record<string, unknown>;
                if (response.ok && typeof body.saved === 'string') {
                    return {
                        ok: true,
                        saved: body.saved,
                        previous: typeof body.previous === 'string' ? body.previous : null,
                        isDefault: body.default === true,
                    };
                }
                return {
                    ok: false,
                    status: response.status,
                    error: typeof body.error === 'string' ? body.error : 'failed',
                    message:
                        typeof body.message === 'string' && body.message !== ''
                            ? body.message
                            : response.status === 403
                              ? __('WordPress refused the save. Reload the page and try again.', 'taw-core')
                              : __('Lens couldn’t save that. Try again.', 'taw-core'),
                };
            } catch {
                return {
                    ok: false,
                    status: 0,
                    error: 'network',
                    message: __('Lens couldn’t reach the site to save. Is it still running?', 'taw-core'),
                };
            }
        },
        async pages(query) {
            const term = query.trim();
            if (term === '' || /^(https?:|mailto:|tel:|#)/i.test(term)) {
                return [];
            }
            const url = `${config.search}${config.search.includes('?') ? '&' : '?'}${new URLSearchParams({
                search: term.replace(/^\//, ''),
                type: 'post',
                per_page: '8',
                _fields: 'title,url',
            })}`;
            try {
                const response = await fetcher(url, {
                    credentials: 'same-origin',
                    headers: { 'x-wp-nonce': config.nonce },
                });
                const list = (await response.json()) as { title?: string; url?: string }[];
                return Array.isArray(list)
                    ? list
                          .filter((item) => typeof item.url === 'string')
                          .map((item) => ({ title: item.title ?? '', url: relative(item.url!, config.home) }))
                    : [];
            } catch {
                return [];
            }
        },
    };
}

/** `https://site.local/about/` → `/about/` on this site; other addresses stay as they are. */
export function relative(url: string, home: string): string {
    try {
        const target = new URL(url);
        const base = new URL(home);
        return target.origin === base.origin ? `${target.pathname}${target.search}${target.hash}` : url;
    } catch {
        return url;
    }
}

import { describe, expect, it, vi } from 'vitest';
import { connectBridge, POLL_MS, PROTOCOL, RETRY_MS, type BridgeDeps, type Connection } from './bridge';

class FakeSource {
    static last: FakeSource | null = null;
    onmessage: ((m: MessageEvent<string>) => void) | null = null;
    onerror: (() => void) | null = null;
    closed = false;
    constructor(public url: string) {
        FakeSource.last = this;
    }
    close() {
        this.closed = true;
    }
    send(data: object) {
        this.onmessage?.({ data: JSON.stringify(data) } as MessageEvent<string>);
    }
}

function setup(answers: unknown[]) {
    const timers: { fn: () => void; ms: number }[] = [];
    const statuses: Connection[] = [];
    const events: unknown[] = [];
    const fetch = vi.fn(async (url: string, init?: RequestInit) => {
        if (String(url).startsWith('http://127.0.0.1')) {
            return { ok: true, status: 202, json: async () => ({}) } as Response;
        }
        const body = answers.length > 1 ? answers.shift() : answers[0];
        return {
            ok: body !== 'error',
            status: body === 'error' ? 403 : 200,
            json: async () => body,
            init,
        } as unknown as Response;
    });
    const deps: BridgeDeps = {
        fetch: fetch as unknown as typeof window.fetch,
        EventSource: FakeSource as unknown as typeof EventSource,
        setTimeout: (fn, ms) => timers.push({ fn, ms }),
        clearTimeout: vi.fn(),
        visible: () => true,
    };
    const client = connectBridge(
        { bridge: 'https://acme.local/wp-json/taw/v1/lens/bridge', nonce: 'n1', protocol: PROTOCOL },
        { status: (s) => statuses.push(s), event: (e) => events.push(e) },
        deps,
    );
    const flush = () => new Promise((r) => setTimeout(r, 0));
    const tick = async () => {
        const next = timers.shift();
        next?.fn();
        await flush();
        return next?.ms;
    };

    return { client, fetch, statuses, events, timers, flush, tick };
}

const running = { running: true, url: 'http://127.0.0.1:47615', token: 'tok', protocol: PROTOCOL };

describe('finding and talking to the bridge', () => {
    it('asks the site with the REST nonce, and again every few seconds while nothing runs', async () => {
        const { fetch, statuses, flush, tick } = setup([{ running: false }, { running: false }, running]);
        await flush();
        expect(fetch).toHaveBeenCalledWith(
            'https://acme.local/wp-json/taw/v1/lens/bridge',
            expect.objectContaining({ headers: { 'X-WP-Nonce': 'n1' } }),
        );
        expect(statuses).toEqual(['off']);
        expect(await tick()).toBe(POLL_MS);
        expect(await tick()).toBe(POLL_MS);
        expect(statuses).toEqual(['off', 'connecting']);
        expect(FakeSource.last!.url).toBe('http://127.0.0.1:47615/events?token=tok');
    });

    it('is connected once the session arrives, and passes every event on', async () => {
        const { statuses, events, flush } = setup([running]);
        await flush();
        FakeSource.last!.send({ type: 'hello', protocol: 1, history: [] });
        FakeSource.last!.send({ type: 'text', turn: 1, delta: 'x' });
        expect(statuses).toEqual(['connecting', 'connected']);
        expect(events).toHaveLength(2);
    });

    it('posts with the token, only to the bridge', async () => {
        const { client, fetch, flush } = setup([running]);
        expect(await client.send('/stop', {})).toEqual({ ok: false, status: 0 });
        await flush();
        expect(await client.send('/message', { text: 'hi' })).toEqual({ ok: true, status: 202 });
        expect(fetch).toHaveBeenLastCalledWith('http://127.0.0.1:47615/message', {
            method: 'POST',
            headers: { 'content-type': 'application/json', 'x-lens-token': 'tok' },
            body: '{"text":"hi"}',
        });
    });

    it('goes back to asking when the bridge stops', async () => {
        const { statuses, timers, flush } = setup([running]);
        await flush();
        FakeSource.last!.send({ type: 'hello' });
        const source = FakeSource.last!;
        source.onerror?.();
        expect(source.closed).toBe(true);
        expect(statuses.at(-1)).toBe('off');
        expect(timers.at(-1)?.ms).toBe(RETRY_MS);
    });

    it('refuses an address that isn’t the loopback, and says when the versions differ', async () => {
        const evil = setup([{ running: true, url: 'http://evil.example:80', token: 't', protocol: PROTOCOL }]);
        await evil.flush();
        expect(evil.statuses).toEqual(['off']);
        const old = setup([{ ...running, protocol: PROTOCOL + 1 }]);
        await old.flush();
        expect(old.statuses).toEqual(['outdated']);
    });

    it('stops asking when closed', async () => {
        const { client, timers, fetch, flush } = setup([{ running: false }]);
        await flush();
        client.close();
        timers.shift()?.fn();
        await flush();
        expect(fetch).toHaveBeenCalledTimes(1);
    });
});

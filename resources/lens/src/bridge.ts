/**
 * The page's side of the bridge (umbrella ADR-0006 § 8).
 *
 * The page never knows the bridge's port or token up front: it asks the site (`GET taw/v1/lens/bridge`, with
 * the REST nonce; TAW\Core\Lens\Lens::bridgeRoute()), which reads the run file `taw lens` wrote. While nothing
 * runs it asks again every few seconds, so starting `taw lens` connects an open page on its own. Asking the
 * site rather than knocking on a closed port also keeps the browser console clean.
 */
import type { BridgeEvent } from './chatModel';

/** The page protocol this build speaks (resources/lens-bridge/lib/bridge.mjs PROTOCOL, TAW\Core\Lens\Bridge::PROTOCOL). */
export const PROTOCOL = 2;

export interface BridgeConfig {
    /** The REST route's URL. */
    bridge: string;
    nonce: string;
    /** The page protocol this build speaks. */
    protocol: number;
}

/**
 * - off: no bridge running (or the site said no);
 * - connecting: found it, opening the stream;
 * - connected: the stream is open and the session arrived;
 * - outdated: the bridge speaks another protocol (this taw/core and the running `taw lens` differ).
 */
export type Connection = 'off' | 'connecting' | 'connected' | 'outdated';

export interface BridgeHandlers {
    status(connection: Connection): void;
    event(event: BridgeEvent): void;
}

export interface BridgeClient {
    /** POSTs to the bridge; false when it isn't connected or refused. */
    send(
        path: '/message' | '/approve' | '/stop' | '/take-over' | '/conversation' | '/note',
        body: object,
    ): Promise<{ ok: boolean; status: number }>;
    close(): void;
}

export interface BridgeDeps {
    fetch: typeof fetch;
    EventSource: typeof EventSource;
    setTimeout: (fn: () => void, ms: number) => unknown;
    clearTimeout: (id: unknown) => void;
    /** Whether the tab is visible: a hidden tab doesn't ask. */
    visible(): boolean;
}

/** How often to ask the site while no bridge runs, and how soon to retry after losing one. */
export const POLL_MS = 3000;
export const RETRY_MS = 1500;

export const browserDeps = (): BridgeDeps => ({
    fetch: (...args) => window.fetch(...args),
    EventSource: window.EventSource,
    setTimeout: (fn, ms) => window.setTimeout(fn, ms),
    clearTimeout: (id) => window.clearTimeout(id as number),
    visible: () => document.visibilityState !== 'hidden',
});

interface Running {
    running: true;
    url: string;
    token: string;
    protocol: number;
}

const isRunning = (info: unknown): info is Running => {
    const i = info as Partial<Running> | null;
    return (
        !!i &&
        i.running === true &&
        typeof i.url === 'string' &&
        /^http:\/\/127\.0\.0\.1:\d+$/.test(i.url) &&
        typeof i.token === 'string'
    );
};

export function connectBridge(
    config: BridgeConfig,
    handlers: BridgeHandlers,
    deps: BridgeDeps = browserDeps(),
): BridgeClient {
    let closed = false;
    let timer: unknown = null;
    let stream: EventSource | null = null;
    let target: { url: string; token: string } | null = null;
    let last: Connection | null = null;

    const status = (next: Connection) => {
        if (next !== last) {
            last = next;
            handlers.status(next);
        }
    };
    const schedule = (ms: number) => {
        if (!closed) {
            timer = deps.setTimeout(() => void discover(), ms);
        }
    };

    async function discover(): Promise<void> {
        timer = null;
        if (closed) {
            return;
        }
        if (!deps.visible()) {
            schedule(POLL_MS);
            return;
        }
        let info: unknown = null;
        try {
            const response = await deps.fetch(config.bridge, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-WP-Nonce': config.nonce },
            });
            info = response.ok ? await response.json() : null;
        } catch {
            info = null;
        }
        if (closed) {
            return;
        }
        if (!isRunning(info)) {
            status('off');
            schedule(POLL_MS);
            return;
        }
        if (info.protocol !== config.protocol) {
            status('outdated');
            schedule(POLL_MS * 4);
            return;
        }

        status('connecting');
        target = { url: info.url, token: info.token };
        const source = new deps.EventSource(`${info.url}/events?token=${encodeURIComponent(info.token)}`);
        stream = source;
        source.onmessage = (message: MessageEvent<string>) => {
            let event: BridgeEvent;
            try {
                event = JSON.parse(message.data) as BridgeEvent;
            } catch {
                return;
            }
            if (event.type === 'hello') {
                status('connected');
            }
            handlers.event(event);
        };
        source.onerror = () => {
            // The bridge stopped (or never answered): forget it and ask the site again.
            source.close();
            if (stream === source) {
                stream = null;
                target = null;
            }
            status('off');
            schedule(RETRY_MS);
        };
    }

    void discover();

    return {
        async send(path, body) {
            if (!target) {
                return { ok: false, status: 0 };
            }
            try {
                const response = await deps.fetch(`${target.url}${path}`, {
                    method: 'POST',
                    headers: { 'content-type': 'application/json', 'x-lens-token': target.token },
                    body: JSON.stringify(body),
                });
                return { ok: response.ok, status: response.status };
            } catch {
                return { ok: false, status: 0 };
            }
        },
        close() {
            closed = true;
            if (timer !== null) {
                deps.clearTimeout(timer);
            }
            stream?.close();
            stream = null;
        },
    };
}

/**
 * The Lens bridge: one agent session for one theme, shared with every Lens page of the site that connects.
 *
 * Page → bridge: HTTP POST (message, approve, stop, take over). Bridge → page: one Server-Sent Events stream.
 * A page that connects (or reconnects after a reload) gets the session so far in its `hello`, so the
 * conversation survives reloads; pending approvals come back as cards.
 *
 * Security (umbrella ADR-0006 § 8): it listens on 127.0.0.1 only; every request needs the one allowed origin
 * (the dev site), a Host header naming the loopback address (against DNS rebinding) and the per-run token,
 * which only the site's PHP hands out, to a logged-in user who can edit the theme.
 */
import { timingSafeEqual } from 'node:crypto';
import { createServer } from 'node:http';
import { Agent } from './agent.mjs';
import { otherSessions } from './busy.mjs';
import { Turns } from './translate.mjs';

/** The page protocol's version; the page refuses a bridge it doesn't know. */
export const PROTOCOL = 1;

const TEXT_MAX = 20_000;
const CONTEXT_MAX = 400_000;
const HISTORY_MAX = 5_000;
const BODY_MAX = 1_000_000;

export const BUSY_REASON =
    'Another Claude session is working in this theme, so TAW Lens writes no files until it is free. ' +
    'Tell the developer what you would change instead; they can wait for the other session, or take over in Lens.';

export const DECLINED = 'The developer skipped this change in TAW Lens. Do not retry it; ask what they want instead.';

export class Bridge {
    /**
     * @param {{theme: string, claude: string, model?: string, resume?: string, prompt?: string, permissionMode?: string,
     *   busyCheck?: (theme: string, own: number[]) => Promise<{pids: number[], since: string|null}|null>,
     *   log?: (line: string) => void}} options
     */
    constructor(options) {
        this.options = options;
        this.busyCheck = options.busyCheck ?? otherSessions;
        this.log = options.log ?? (() => {});
        this.history = [];
        this.clients = new Set();
        this.pending = new Map();
        this.turns = new Turns(options.theme);
        this.busy = null;
        this.takenOver = false;
        this.takenPids = [];
        this.lastText = '';
        this.agent = new Agent({
            claude: options.claude,
            theme: options.theme,
            model: options.model,
            resume: options.resume,
            prompt: options.prompt,
            permissionMode: options.permissionMode,
            guard: () => this.guard(),
        });
        this.agent.on('event', (ev) => this.onAgentEvent(ev));
        this.agent.on('exit', (code, stderr) => {
            for (const event of this.turns.exited(stderr)) {
                this.emit(event);
            }
            this.pending.clear();
            if (code !== 0 && code !== null) {
                this.log(`Claude exited (${code}). The next message starts it again, in the same session.`);
            }
        });
    }

    get working() {
        return this.turns.active;
    }

    get session() {
        return this.agent.session ?? this.turns.session;
    }

    /** Starts the agent ahead of the first message (its start-up then isn't on the first reply's clock). */
    start() {
        this.agent.start();
    }

    onAgentEvent(ev) {
        if (ev.type === 'control_cancel_request' && this.pending.has(ev.request_id)) {
            this.pending.delete(ev.request_id);
            this.emit({ type: 'approval-done', id: String(ev.request_id), allow: false });
            return;
        }
        for (const event of this.turns.translate(ev)) {
            if (event.type === 'approval') {
                this.pending.set(event.id, ev.request.input);
            }
            if (event.type === 'done') {
                this.log(`Turn ${event.turn}: ${event.outcome}${event.changed.length ? `, changed ${event.changed.join(', ')}` : ''}`);
            }
            this.emit(event);
        }
    }

    /** Sends one event to every page and keeps it for pages that connect later. */
    emit(event) {
        const last = this.history[this.history.length - 1];
        if (event.type === 'text' && last?.type === 'text' && last.turn === event.turn) {
            this.history[this.history.length - 1] = { ...last, delta: last.delta + event.delta };
        } else {
            this.history.push(event);
            if (this.history.length > HISTORY_MAX) {
                this.history.splice(0, this.history.length - HISTORY_MAX);
            }
        }
        const line = `data: ${JSON.stringify(event)}\n\n`;
        for (const res of this.clients) {
            res.write(line);
        }
    }

    hello() {
        return {
            type: 'hello',
            protocol: PROTOCOL,
            session: this.session,
            working: this.working,
            busy: this.busy,
            takenOver: this.takenOver,
            history: this.history,
        };
    }

    /**
     * A message from the page. `context` is the page's description of the selections (built from the
     * source map); `chips` are their short names for the transcript. `from: 'edit'` marks a message the
     * page wrote for an edit made on the page (template text, which Lens never saves itself).
     * @returns {{turn: number}|{error: string, status: number}}
     */
    message({ text, context = '', chips = [], from }) {
        if (typeof text !== 'string' || text.trim() === '' || text.length > TEXT_MAX) {
            return { error: 'text', status: 422 };
        }
        if (typeof context !== 'string' || context.length > CONTEXT_MAX || !Array.isArray(chips)) {
            return { error: 'context', status: 422 };
        }
        if (this.working) {
            return { error: 'working', status: 409 };
        }
        const turn = this.turns.start();
        this.lastText = text;
        this.emit({
            type: 'turn',
            turn,
            text,
            chips: chips.filter((c) => typeof c === 'string').slice(0, 20).map((c) => c.slice(0, 80)),
            ...(from === 'edit' ? { from } : {}),
        });
        this.log(`Turn ${turn}: ${text.length > 70 ? `${text.slice(0, 69)}…` : text}`);
        this.agent.send(context ? `${text}\n\n${context}` : text);
        void this.refreshBusy();

        return { turn };
    }

    approve(id, allow) {
        if (!this.pending.has(id)) {
            return false;
        }
        const input = this.pending.get(id);
        this.pending.delete(id);
        this.agent.respond(id, allow, input, DECLINED);
        this.emit({ type: 'approval-done', id, allow });

        return true;
    }

    stop() {
        if (!this.working) {
            return;
        }
        this.turns.stop();
        this.agent.interrupt();
    }

    /** "Take over" (asked twice on the page): write anyway, until another session starts. */
    takeOver() {
        if (!this.busy) {
            return;
        }
        this.takenOver = true;
        this.takenPids = [...this.busy.pids];
        this.emit({ type: 'busy', busy: this.busy, takenOver: true });
    }

    /** The PreToolUse guard: why a write is refused, or null. */
    guard() {
        return this.busy && !this.takenOver ? BUSY_REASON : null;
    }

    async refreshBusy() {
        const own = this.agent.child?.pid ? [this.agent.child.pid] : [];
        const next = await this.busyCheck(this.options.theme, own);
        if (next && this.takenOver && next.pids.some((pid) => !this.takenPids.includes(pid))) {
            this.takenOver = false;
        }
        if (!next) {
            this.takenOver = false;
            this.takenPids = [];
        }
        if (JSON.stringify(next) !== JSON.stringify(this.busy)) {
            this.busy = next;
            this.emit({ type: 'busy', busy: next, takenOver: this.takenOver });
        }
    }

    close() {
        for (const res of this.clients) {
            res.end();
        }
        this.clients.clear();
        this.agent.close();
    }
}

const sameToken = (given, token) => {
    if (typeof given !== 'string' || given.length !== token.length) {
        return false;
    }
    return timingSafeEqual(Buffer.from(given), Buffer.from(token));
};

const readBody = (req) =>
    new Promise((resolve, reject) => {
        let body = '';
        req.setEncoding('utf8');
        req.on('data', (chunk) => {
            body += chunk;
            if (body.length > BODY_MAX) {
                reject(new Error('too large'));
                req.destroy();
            }
        });
        req.on('end', () => resolve(body));
        req.on('error', reject);
    });

const json = (res, status, body) => {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(body === undefined ? '' : JSON.stringify(body));
};

/**
 * Serves the bridge on 127.0.0.1. Tries `port` first and any free port if it is taken.
 * @returns {Promise<{server: import('node:http').Server, port: number}>}
 */
export function serve(bridge, { port = 0, token, origins, busyEvery = 4000 }) {
    let actual = 0;
    const server = createServer(async (req, res) => {
        const origin = req.headers.origin ?? '';
        const allowed = origins.includes(origin);
        res.setHeader('Vary', 'Origin');
        if (allowed) {
            res.setHeader('Access-Control-Allow-Origin', origin);
            res.setHeader('Access-Control-Allow-Headers', 'content-type, x-lens-token');
            res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            // Chrome's Private/Local Network Access preflight.
            res.setHeader('Access-Control-Allow-Private-Network', 'true');
        }
        const hostOk = req.headers.host === `127.0.0.1:${actual}` || req.headers.host === `localhost:${actual}`;
        if (!allowed || !hostOk) {
            json(res, 403);
            return;
        }
        if (req.method === 'OPTIONS') {
            res.writeHead(204).end();
            return;
        }
        const url = new URL(req.url ?? '/', 'http://127.0.0.1');
        if (!sameToken(req.headers['x-lens-token'] ?? url.searchParams.get('token'), token)) {
            json(res, 401);
            return;
        }

        if (url.pathname === '/events' && req.method === 'GET') {
            res.writeHead(200, {
                'Content-Type': 'text/event-stream',
                'Cache-Control': 'no-cache, no-transform',
                Connection: 'keep-alive',
            });
            res.write(`data: ${JSON.stringify(bridge.hello())}\n\n`);
            bridge.clients.add(res);
            req.on('close', () => bridge.clients.delete(res));
            return;
        }
        if (req.method !== 'POST') {
            json(res, 404);
            return;
        }
        let body;
        try {
            body = JSON.parse((await readBody(req)) || '{}');
        } catch {
            json(res, 400);
            return;
        }
        switch (url.pathname) {
            case '/message': {
                const result = bridge.message(body ?? {});
                if ('error' in result) {
                    json(res, result.status, { error: result.error });
                } else {
                    json(res, 202, result);
                }
                return;
            }
            case '/approve':
                json(res, bridge.approve(String(body?.id ?? ''), body?.allow === true) ? 204 : 404);
                return;
            case '/stop':
                bridge.stop();
                json(res, 204);
                return;
            case '/take-over':
                bridge.takeOver();
                json(res, 204);
                return;
            default:
                json(res, 404);
        }
    });

    // Keeps idle streams open through proxies and tells the bridge when a page is gone; checks the lock.
    const timers = [
        setInterval(() => {
            for (const res of bridge.clients) {
                res.write(': ping\n\n');
            }
        }, 15_000),
        setInterval(() => {
            if (bridge.clients.size > 0 || bridge.working) {
                void bridge.refreshBusy();
            }
        }, busyEvery),
    ];
    timers.forEach((t) => t.unref());
    server.on('close', () => timers.forEach(clearInterval));

    const listen = (p) =>
        new Promise((resolve, reject) => {
            const onError = (error) => {
                server.off('listening', onListening);
                reject(error);
            };
            const onListening = () => {
                server.off('error', onError);
                actual = server.address().port;
                resolve({ server, port: actual });
            };
            server.once('error', onError);
            server.once('listening', onListening);
            server.listen(p, '127.0.0.1');
        });

    return listen(port).catch((error) => {
        if (error?.code === 'EADDRINUSE' && port !== 0) {
            return listen(0);
        }
        throw error;
    });
}

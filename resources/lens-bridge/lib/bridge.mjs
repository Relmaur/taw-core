/**
 * The Lens bridge: one agent session for one theme, shared with every Lens page of the site that connects.
 *
 * Page → bridge: HTTP POST (message, approve, stop, take over). Bridge → page: one Server-Sent Events stream.
 * A page that connects (or reconnects after a reload) gets the session so far in its `hello`, so the
 * conversation survives reloads; pending approvals come back as cards.
 *
 * Conversations (taw-core ADR-0021): every conversation's events go to a transcript in the theme's ignored
 * `.taw/lens/conversations/` (transcript.mjs). `--resume` and the page's conversation menu reopen one: its
 * transcript replays in `hello`, followed by a `resumed` marker, and Claude resumes its session.
 *
 * Security (umbrella ADR-0006 § 8): it listens on 127.0.0.1 only; every request needs the one allowed origin
 * (the dev site), a Host header naming the loopback address (against DNS rebinding) and the per-run token,
 * which only the site's PHP hands out, to a logged-in user who can edit the theme.
 */
import { timingSafeEqual } from 'node:crypto';
import { createServer } from 'node:http';
import { Agent } from './agent.mjs';
import { otherSessions } from './busy.mjs';
import * as transcripts from './transcript.mjs';
import { Turns } from './translate.mjs';

/** The page protocol's version; the page refuses a bridge it doesn't know. 2: conversations (ADR-0021). */
export const PROTOCOL = 2;

const TEXT_MAX = 20_000;
const CONTEXT_MAX = 400_000;
const HISTORY_MAX = 5_000;
const BODY_MAX = 1_000_000;
const PAGE_MAX = 300;
const NAME_MAX = 200;

/** Events stamped with the time they happened (the menu's "last active", the history's dividers). */
const STAMPED = new Set(['turn', 'done', 'saved']);

/** Events that describe the bridge now, not the conversation: never written to a transcript. */
const UNRECORDED = new Set(['busy']);

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
        this.now = options.now ?? (() => new Date().toISOString());
        this.clients = new Set();
        this.pending = new Map();
        this.busy = null;
        this.takenOver = false;
        this.takenPids = [];
        this.lastText = '';
        const resume = this.load(options.resume || null) ?? (options.resume || '');
        this.agent = new Agent({
            claude: options.claude,
            theme: options.theme,
            model: options.model,
            resume,
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

    /**
     * Makes `session`'s conversation the current one (null: a new one): its transcript becomes the history,
     * followed by a `resumed` marker. Returns the session id to resume Claude with, or null.
     */
    load(session) {
        const theme = this.options.theme;
        const id = session ? transcripts.find(theme, session) : null;
        const { events, session: latest } = id ? transcripts.load(theme, id) : { events: [], session: null };
        this.transcript = new transcripts.Transcript(theme, id);
        this.history = events.length > 0 ? [...events, { type: 'resumed', at: this.now() }] : [];
        this.turns = new Turns(theme);
        this.turns.turn = events.reduce((max, e) => (e.type === 'turn' && e.turn > max ? e.turn : max), 0);

        return latest;
    }

    /** The menu's list: the current conversation (once something was said), then the newest others. */
    conversations() {
        const current = transcripts.summary(this.history);
        const others = transcripts
            .list(this.options.theme, transcripts.LIST_MAX + 1)
            .filter((c) => c.id !== this.transcript.id)
            .map((c) => ({ ...c, current: false }));
        const head = current.title !== '' ? [{ id: this.transcript.id ?? '', ...current, current: true }] : [];

        return [...head, ...others].slice(0, transcripts.LIST_MAX);
    }

    onAgentEvent(ev) {
        if (typeof ev.session_id === 'string') {
            this.transcript.named(ev.session_id);
        }
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

    /** Sends one event to every page and keeps it for pages that connect later (and in the transcript). */
    emit(event) {
        if (STAMPED.has(event.type) && typeof event.at !== 'string') {
            event = { ...event, at: this.now() };
        }
        if (!UNRECORDED.has(event.type)) {
            this.transcript.record(event);
        }
        const last = this.history[this.history.length - 1];
        if (event.type === 'text' && last?.type === 'text' && last.turn === event.turn) {
            this.history[this.history.length - 1] = { ...last, delta: last.delta + event.delta };
        } else {
            this.history.push(event);
            if (this.history.length > HISTORY_MAX) {
                this.history.splice(0, this.history.length - HISTORY_MAX);
            }
        }
        this.send(event);
        if (event.type === 'turn' || event.type === 'done' || event.type === 'saved') {
            this.send({ type: 'conversations', conversations: this.conversations() });
        }
    }

    /** Sends to every page without keeping it. */
    send(event) {
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
            conversations: this.conversations(),
        };
    }

    /**
     * A message from the page. `context` is the page's description of the selections (built from the
     * source map); `chips` are their short names for the transcript. `from: 'edit'` marks a message the
     * page wrote for an edit made on the page (template text, which Lens never saves itself).
     * @returns {{turn: number}|{error: string, status: number}}
     */
    message({ text, context = '', chips = [], from, page }) {
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
            ...(typeof page === 'string' && page.startsWith('/') && page.length <= PAGE_MAX ? { page } : {}),
        });
        this.log(`Turn ${turn}: ${text.length > 70 ? `${text.slice(0, 69)}…` : text}`);
        this.agent.send(context ? `${text}\n\n${context}` : text);
        void this.refreshBusy();

        return { turn };
    }

    /**
     * Opens another conversation (a session id from the menu), or starts a new one (null). Refused while a
     * turn runs or an approval waits: the page says to stop Claude first.
     * @returns {{ok: true}|{error: string, status: number}}
     */
    conversation(session) {
        if (session !== null && !transcripts.validId(session)) {
            return { error: 'session', status: 422 };
        }
        if (this.working || this.pending.size > 0) {
            return { error: 'working', status: 409 };
        }
        if (session !== null && session === this.transcript.id) {
            return { ok: true };
        }
        if (session !== null && transcripts.load(this.options.theme, session).events.length === 0) {
            return { error: 'unknown', status: 404 };
        }
        this.transcript.close();
        const resume = this.load(session);
        this.agent.reset(resume);
        this.lastText = '';
        const title = transcripts.summary(this.history).title;
        this.log(session ? `Opened the conversation “${title}”.` : 'Started a new conversation.');
        this.send(this.hello());

        return { ok: true };
    }

    /**
     * A field the page saved (or undid) while connected: it goes in the conversation, so the transcript and
     * every open page show it. `id` is the page's own, so it doesn't show its own save twice.
     */
    note({ id, name, undone }) {
        if (typeof id !== 'string' || !/^[A-Za-z0-9_-]{1,64}$/.test(id) || typeof name !== 'string' || name === '' || name.length > NAME_MAX) {
            return false;
        }
        this.emit({ type: 'saved', id, name, ...(undone === true ? { undone: true } : {}) });

        return true;
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
        this.transcript.close();
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
            case '/conversation': {
                const session = body?.session ?? null;
                const result = bridge.conversation(typeof session === 'string' || session === null ? session : '');
                json(res, 'error' in result ? result.status : 204, 'error' in result ? { error: result.error } : undefined);
                return;
            }
            case '/note':
                json(res, bridge.note(body ?? {}) ? 204 : 422);
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

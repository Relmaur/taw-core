/**
 * Conversations Lens keeps (taw-core ADR-0021): one transcript per conversation, the page events the bridge
 * emitted (plus field saves the page reported), one JSON line each, in `<theme>/.taw/lens/conversations/`.
 *
 * The bridge never reads Claude Code's own session files: their format isn't ours, they hold the whole
 * selection payloads, and they don't record whether an approval was applied or which fields were saved.
 *
 * - A conversation is named by the first session id Claude reported for it. If a resumed session comes back
 *   under another id, a `meta` line records it, and resuming uses the latest.
 * - The folder sits inside `.taw/lens/`, which ignores itself (runfile.mjs), so transcripts never reach a commit.
 * - Only the newest KEEP transcripts are kept.
 */
import { appendFileSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { RUN_DIR } from './runfile.mjs';

export const DIR = 'conversations';

/** How many conversations the menu lists, and how many transcripts stay on disk. */
export const LIST_MAX = 8;
export const KEEP = 50;

const TITLE_MAX = 80;

/** A session id Lens accepts as a file name: what Claude Code uses (a UUID), nothing that walks folders. */
export const validId = (id) => typeof id === 'string' && /^[A-Za-z0-9][A-Za-z0-9_-]{3,63}$/.test(id);

export const folder = (theme) => join(theme, RUN_DIR, DIR);
const file = (theme, id) => join(folder(theme), `${id}.jsonl`);

/** A message as one title line. */
export function titleOf(text) {
    const line = String(text ?? '')
        .split('\n')
        .map((l) => l.trim())
        .find((l) => l !== '');
    if (!line) {
        return '';
    }
    return line.length > TITLE_MAX ? `${line.slice(0, TITLE_MAX - 1)}…` : line;
}

/**
 * What the menu shows for a conversation's events: its title (the first message), when it was last active,
 * and how many changes it made (files Claude changed and fields saved from the page, each counted once).
 * @returns {{title: string, at: string|null, changes: number}}
 */
export function summary(events) {
    let title = '';
    let at = null;
    const changes = new Set();
    for (const event of events) {
        if (event.type === 'turn' && title === '') {
            title = titleOf(event.text);
        }
        if (typeof event.at === 'string') {
            at = event.at;
        }
        if (event.type === 'done' && Array.isArray(event.changed)) {
            event.changed.forEach((path) => changes.add(`file:${path}`));
        }
        if (event.type === 'saved' && typeof event.name === 'string' && !event.undone) {
            changes.add(`field:${event.name}`);
        }
    }

    return { title, at, changes: changes.size };
}

/**
 * Closes what a transcript left open: a turn without its end (the bridge stopped mid-turn) ends as stopped,
 * so a replayed conversation never looks like it's still working. Folds a save and its undo into one line.
 */
export function settle(events) {
    const out = [];
    const open = new Set();
    const saves = new Map();
    for (const event of events) {
        if (event.type === 'turn') {
            open.add(event.turn);
        } else if (event.type === 'done') {
            open.delete(event.turn);
        }
        if (event.type === 'saved' && saves.has(event.id)) {
            out[saves.get(event.id)] = { ...out[saves.get(event.id)], undone: event.undone === true };
            continue;
        }
        if (event.type === 'saved') {
            saves.set(event.id, out.length);
        }
        out.push(event);
    }
    for (const turn of open) {
        out.push({ type: 'done', turn, outcome: 'stopped', message: '', changed: [] });
    }

    return out;
}

/** Reads a transcript: its events (settled) and the session id to resume it with. */
export function load(theme, id) {
    if (!validId(id)) {
        return { events: [], session: null };
    }
    let text = '';
    try {
        text = readFileSync(file(theme, id), 'utf8');
    } catch {
        return { events: [], session: null };
    }
    const events = [];
    let session = id;
    for (const line of text.split('\n')) {
        if (!line.trim()) {
            continue;
        }
        let event;
        try {
            event = JSON.parse(line);
        } catch {
            continue;
        }
        if (event?.type === 'meta') {
            if (validId(event.session)) {
                session = event.session;
            }
        } else if (event && typeof event.type === 'string') {
            events.push(event);
        }
    }

    return { events: settle(events), session };
}

/** The conversation a session id belongs to: its own transcript, or the one whose `meta` names it. */
export function find(theme, session) {
    if (!validId(session)) {
        return null;
    }
    try {
        statSync(file(theme, session));
        return session;
    } catch {
        // Not a conversation's first id: look for it as a later one.
    }
    for (const id of ids(theme)) {
        if (load(theme, id).session === session) {
            return id;
        }
    }

    return null;
}

/** The transcripts on disk, newest first. */
export function ids(theme) {
    let names = [];
    try {
        names = readdirSync(folder(theme));
    } catch {
        return [];
    }

    return names
        .filter((name) => name.endsWith('.jsonl') && validId(name.slice(0, -6)))
        .map((name) => {
            let mtime = 0;
            try {
                mtime = statSync(join(folder(theme), name)).mtimeMs;
            } catch {
                // Gone meanwhile.
            }
            return { id: name.slice(0, -6), mtime };
        })
        .sort((a, b) => b.mtime - a.mtime)
        .map((entry) => entry.id);
}

/** The menu's list: the newest conversations with something said in them. */
export function list(theme, limit = LIST_MAX) {
    const out = [];
    for (const id of ids(theme)) {
        if (out.length >= limit) {
            break;
        }
        const info = summary(load(theme, id).events);
        if (info.title !== '') {
            out.push({ id, ...info });
        }
    }

    return out;
}

/** Keeps the newest KEEP transcripts. */
export function prune(theme, keep = KEEP) {
    for (const id of ids(theme).slice(keep)) {
        rmSync(file(theme, id), { force: true });
    }
}

/**
 * The transcript of the conversation running now. Events wait in memory until Claude names the session;
 * streamed text is written as one line per run of text, not one per delta.
 */
export class Transcript {
    /** @param {string} theme  @param {string|null} id  a conversation that already has a transcript */
    constructor(theme, id = null) {
        this.theme = theme;
        this.id = id;
        this.session = id;
        this.queue = [];
        this.text = null;
    }

    /** Claude reported its session id. */
    named(session) {
        if (!validId(session) || session === this.session) {
            return;
        }
        if (this.id === null) {
            this.id = session;
            this.session = session;
            this.flush();
            prune(this.theme);
        } else {
            this.session = session;
            this.write({ type: 'meta', session });
        }
    }

    /** One page event. */
    record(event) {
        if (event.type === 'text') {
            if (this.text && this.text.turn === event.turn) {
                this.text = { ...this.text, delta: this.text.delta + event.delta };
                return;
            }
            this.flushText();
            this.text = { ...event };
            return;
        }
        this.flushText();
        this.write(event);
    }

    flushText() {
        if (this.text) {
            const text = this.text;
            this.text = null;
            this.write(text);
        }
    }

    /** Writes what waited for a session id, in order (text still streaming stays pending). */
    flush() {
        if (this.id === null) {
            return;
        }
        const queued = this.queue;
        this.queue = [];
        queued.forEach((event) => this.write(event));
    }

    /** The bridge stops or switches away: text in progress is written too. */
    close() {
        this.flushText();
    }

    write(event) {
        if (this.id === null) {
            this.queue.push(event);
            return;
        }
        try {
            mkdirSync(folder(this.theme), { recursive: true, mode: 0o700 });
            appendFileSync(file(this.theme, this.id), `${JSON.stringify(event)}\n`, { mode: 0o600 });
        } catch {
            // The theme folder went away or is read-only: the conversation goes on, unrecorded.
        }
    }
}

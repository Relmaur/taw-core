/**
 * The Claude Code CLI's stream-json events → the events the Lens page reads (umbrella ADR-0006 § 8).
 *
 * Pure: no I/O, so it is tested without a CLI. The page protocol is ours and stays the same if the bridge
 * ever moves from the CLI to the Agent SDK; only this file would change.
 *
 * Page events (all carry `type`; turn events carry `turn`):
 *   text        { delta }                         Claude's reply, streamed
 *   tool        { id, name, kind, target }        Claude started using a tool
 *   tool-done   { id, ok }                        …and it finished
 *   approval    { id, tool, kind, target, input } a change waits for the developer's OK
 *   done        { outcome: ok|stopped|error, message, changed }
 */
import { isAbsolute, relative } from 'node:path';

/** Tools that write files: the theme lock refuses them, and a finished one counts as a change. */
export const WRITE_TOOLS = new Set(['Edit', 'MultiEdit', 'Write', 'NotebookEdit']);

/** Tools the theme lock refuses while another session holds the theme (Bash can write too). */
export const GUARDED_TOOLS = [...WRITE_TOOLS, 'Bash'];

/** The most of a tool's input the page receives (a Write of a large file). */
const INPUT_MAX = 100_000;

/** What kind of step a tool is, for the activity line. */
export function toolKind(name) {
    switch (name) {
        case 'Read':
            return 'read';
        case 'Glob':
        case 'Grep':
        case 'LS':
            return 'search';
        case 'Edit':
        case 'MultiEdit':
        case 'NotebookEdit':
            return 'edit';
        case 'Write':
            return 'write';
        case 'Bash':
            return 'run';
        case 'Skill':
            return 'skill';
        case 'TodoWrite':
            return 'plan';
        default:
            return 'other';
    }
}

/** A path as the developer reads it: relative to the theme when it's inside it. */
export function relPath(file, theme) {
    if (typeof file !== 'string' || file === '') {
        return '';
    }
    if (!isAbsolute(file)) {
        return file;
    }
    const rel = relative(theme, file);

    return rel !== '' && !rel.startsWith('..') && !isAbsolute(rel) ? rel : file;
}

const firstLine = (text, max = 200) => {
    const line = String(text ?? '').split('\n')[0];
    return line.length > max ? `${line.slice(0, max - 1)}…` : line;
};

/** What a tool works on: a file, a pattern, a command. */
export function toolTarget(name, input, theme) {
    const i = input && typeof input === 'object' ? input : {};
    switch (toolKind(name)) {
        case 'read':
        case 'edit':
        case 'write':
            return relPath(i.file_path ?? i.notebook_path ?? i.path, theme);
        case 'search':
            return firstLine(i.pattern ?? relPath(i.path, theme));
        case 'run':
            return firstLine(i.command);
        case 'skill':
            return firstLine(i.skill ?? i.command ?? '');
        default:
            return '';
    }
}

const clip = (value) => (typeof value === 'string' && value.length > INPUT_MAX ? `${value.slice(0, INPUT_MAX)}\n…` : value);

/** The part of a tool's input an approval card shows. */
export function approvalInput(name, input, theme) {
    const i = input && typeof input === 'object' ? input : {};
    switch (name) {
        case 'Edit':
            return { file: relPath(i.file_path, theme), edits: [{ old: clip(i.old_string ?? ''), new: clip(i.new_string ?? '') }] };
        case 'MultiEdit':
            return {
                file: relPath(i.file_path, theme),
                edits: (Array.isArray(i.edits) ? i.edits : []).map((e) => ({ old: clip(e?.old_string ?? ''), new: clip(e?.new_string ?? '') })),
            };
        case 'Write':
            return { file: relPath(i.file_path, theme), content: clip(i.content ?? '') };
        case 'Bash':
            return { command: clip(i.command ?? ''), description: firstLine(i.description ?? '') };
        default:
            return { json: clip(JSON.stringify(i, null, 2)) };
    }
}

/** One Lens session's turns: feeds CLI events in, gets page events out. */
export class Turns {
    constructor(theme) {
        this.theme = theme;
        this.turn = 0;
        this.active = false;
        this.stopping = false;
        this.tools = new Map();
        this.changed = new Set();
        this.session = null;
    }

    /** A message was sent: a new turn starts. Returns its number. */
    start() {
        this.turn += 1;
        this.active = true;
        this.stopping = false;
        this.tools.clear();
        this.changed.clear();

        return this.turn;
    }

    /** Stop was pressed: the turn's end reads as stopped, not failed. */
    stop() {
        if (this.active) {
            this.stopping = true;
        }
    }

    /** One CLI event → the page events it means (often none). */
    translate(ev) {
        if (!ev || typeof ev !== 'object') {
            return [];
        }
        if (typeof ev.session_id === 'string') {
            this.session = ev.session_id;
        }
        // A sub-agent's own events stay inside its tool step.
        if (ev.parent_tool_use_id) {
            return [];
        }
        const turn = this.turn;

        if (ev.type === 'stream_event') {
            const delta = ev.event?.type === 'content_block_delta' ? ev.event.delta : null;
            return this.active && delta?.type === 'text_delta' && delta.text ? [{ type: 'text', turn, delta: delta.text }] : [];
        }

        if (ev.type === 'assistant') {
            const out = [];
            for (const block of ev.message?.content ?? []) {
                if (block?.type === 'tool_use' && typeof block.id === 'string' && !this.tools.has(block.id)) {
                    const name = String(block.name ?? '');
                    const target = toolTarget(name, block.input, this.theme);
                    this.tools.set(block.id, { name, target });
                    out.push({ type: 'tool', turn, id: block.id, name, kind: toolKind(name), target });
                }
            }
            return out;
        }

        if (ev.type === 'user') {
            const out = [];
            const content = ev.message?.content;
            for (const block of Array.isArray(content) ? content : []) {
                if (block?.type !== 'tool_result' || typeof block.tool_use_id !== 'string') {
                    continue;
                }
                const tool = this.tools.get(block.tool_use_id);
                const ok = block.is_error !== true;
                if (ok && tool && WRITE_TOOLS.has(tool.name) && tool.target) {
                    this.changed.add(tool.target);
                }
                out.push({ type: 'tool-done', turn, id: block.tool_use_id, ok });
            }
            return out;
        }

        if (ev.type === 'control_request' && ev.request?.subtype === 'can_use_tool') {
            const name = String(ev.request.tool_name ?? '');
            return [
                {
                    type: 'approval',
                    turn,
                    id: String(ev.request_id),
                    tool: name,
                    kind: toolKind(name),
                    target: toolTarget(name, ev.request.input, this.theme),
                    input: approvalInput(name, ev.request.input, this.theme),
                },
            ];
        }

        if (ev.type === 'result') {
            if (!this.active) {
                return [];
            }
            const failed = ev.is_error === true || (typeof ev.subtype === 'string' && ev.subtype !== 'success');
            const outcome = this.stopping ? 'stopped' : failed ? 'error' : 'ok';
            const message =
                outcome === 'error'
                    ? firstLine(Array.isArray(ev.errors) && ev.errors.length ? ev.errors.join('; ') : (ev.result ?? ev.subtype ?? ''), 600)
                    : '';
            return [this.finish(outcome, message)];
        }

        return [];
    }

    /** The agent process ended. Mid-turn, that is an error; the changes so far are listed. */
    exited(detail = '') {
        return this.active ? [this.finish('error', 'agent-exit', detail)] : [];
    }

    finish(outcome, message, detail = '') {
        this.active = false;
        this.stopping = false;
        const done = { type: 'done', turn: this.turn, outcome, message, changed: [...this.changed] };
        if (detail) {
            // The end of stderr says why; the start is usually start-up noise.
            done.detail = String(detail).slice(-2000);
        }

        return done;
    }
}

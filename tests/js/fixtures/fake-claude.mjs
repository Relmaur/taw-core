// A stand-in for the `claude` CLI in stream-json mode, for the Lens bridge tests. It answers `initialize`,
// then plays one scripted turn per user message, chosen by a keyword in the message:
//   "edit"  → a Write tool asking for approval (the theme-lock hook runs first), then a reply
//   "slow"  → streams text until interrupted
//   "crash" → exits mid-turn
//   else    → a plain streamed reply
// It records its arguments in FAKE_CLAUDE_ARGS (a file) when that's set.
import { writeFileSync } from 'node:fs';
import { createInterface } from 'node:readline';

if (process.env.FAKE_CLAUDE_ARGS) {
    writeFileSync(process.env.FAKE_CLAUDE_ARGS, JSON.stringify({ args: process.argv.slice(2), cwd: process.cwd() }));
}

const session = 'fake-session-1';
const out = (ev) => process.stdout.write(`${JSON.stringify({ ...ev, session_id: session })}\n`);
const waiting = new Map();
let n = 0;
let interrupted = false;

const request = (body) =>
    new Promise((resolve) => {
        const id = `req-${++n}`;
        waiting.set(id, resolve);
        out({ type: 'control_request', request_id: id, request: body });
    });

const text = (t) => out({ type: 'stream_event', parent_tool_use_id: null, event: { type: 'content_block_delta', index: 0, delta: { type: 'text_delta', text: t } } });
const result = (ok = true, extra = {}) =>
    out({ type: 'result', subtype: ok ? 'success' : 'error_during_execution', is_error: !ok, result: ok ? 'done' : '', ...extra });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function turn(message) {
    interrupted = false;
    if (message.includes('edit')) {
        const input = { file_path: `${process.cwd()}/Blocks/Hero/style.css`, content: '.hero { margin: 0; }\n' };
        out({ type: 'assistant', parent_tool_use_id: null, message: { content: [{ type: 'tool_use', id: 'tool-1', name: 'Write', input }] } });
        const hook = await request({ subtype: 'hook_callback', callback_id: 'lens_theme_lock', input: { tool_name: 'Write', tool_input: input } });
        const denied = hook?.hookSpecificOutput?.permissionDecision === 'deny';
        if (denied) {
            out({ type: 'user', parent_tool_use_id: null, message: { content: [{ type: 'tool_result', tool_use_id: 'tool-1', is_error: true, content: hook.hookSpecificOutput.permissionDecisionReason }] } });
            text('The theme is busy.');
            result();
            return;
        }
        const answer = await request({ subtype: 'can_use_tool', tool_name: 'Write', input });
        const allowed = answer?.behavior === 'allow';
        out({ type: 'user', parent_tool_use_id: null, message: { content: [{ type: 'tool_result', tool_use_id: 'tool-1', is_error: !allowed, content: allowed ? 'ok' : answer.message }] } });
        text(allowed ? 'Changed the margin.' : 'Skipped.');
        result();
        return;
    }
    if (message.includes('slow')) {
        for (let i = 0; i < 200 && !interrupted; i++) {
            text(`${i} `);
            await sleep(20);
        }
        result(!interrupted);
        return;
    }
    if (message.includes('crash')) {
        text('Starting…');
        process.stderr.write('fatal: the connection dropped\n');
        process.exit(3);
    }
    text('Hello ');
    text('there.');
    result();
}

const lines = createInterface({ input: process.stdin });
lines.on('line', (line) => {
    const msg = JSON.parse(line);
    if (msg.type === 'control_request' && msg.request?.subtype === 'initialize') {
        out({ type: 'control_response', response: { subtype: 'success', request_id: msg.request_id, response: {} } });
        out({ type: 'system', subtype: 'init' });
    } else if (msg.type === 'control_request' && msg.request?.subtype === 'interrupt') {
        interrupted = true;
        out({ type: 'control_response', response: { subtype: 'success', request_id: msg.request_id } });
    } else if (msg.type === 'control_response') {
        const resolve = waiting.get(msg.response.request_id);
        waiting.delete(msg.response.request_id);
        resolve?.(msg.response.response);
    } else if (msg.type === 'user') {
        void turn(String(msg.message.content));
    }
});
lines.on('close', () => process.exit(0));

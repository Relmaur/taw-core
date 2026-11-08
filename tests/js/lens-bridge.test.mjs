// Tests for the TAW Lens bridge (resources/lens-bridge/), against a fake `claude` (fixtures/fake-claude.mjs).
// Run: node --test tests/js/
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readFileSync, realpathSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { request } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, afterEach, beforeEach, describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

import { parseArgs } from '../../resources/lens-bridge/bridge.mjs';
import { agentArgs, TOOLS } from '../../resources/lens-bridge/lib/agent.mjs';
import { Bridge, BUSY_REASON, serve } from '../../resources/lens-bridge/lib/bridge.mjs';
import { claudePids, pidsIn } from '../../resources/lens-bridge/lib/busy.mjs';
import * as runfile from '../../resources/lens-bridge/lib/runfile.mjs';
import { approvalInput, relPath, toolKind, toolTarget, Turns } from '../../resources/lens-bridge/lib/translate.mjs';

const FAKE = fileURLToPath(new URL('./fixtures/fake-claude.mjs', import.meta.url));
const ORIGIN = 'https://acme.local';
const TOKEN = 'a'.repeat(48);

describe('translating the CLI’s events', () => {
    const theme = '/sites/acme/wp-content/themes/acme';

    it('names tools and what they work on, relative to the theme', () => {
        assert.equal(toolKind('Read'), 'read');
        assert.equal(toolKind('Grep'), 'search');
        assert.equal(toolKind('Edit'), 'edit');
        assert.equal(toolKind('Bash'), 'run');
        assert.equal(toolKind('Mystery'), 'other');
        assert.equal(relPath(`${theme}/Blocks/Hero/Hero.php`, theme), 'Blocks/Hero/Hero.php');
        assert.equal(relPath('/etc/hosts', theme), '/etc/hosts');
        assert.equal(toolTarget('Bash', { command: 'npm run build\nnpm test' }, theme), 'npm run build');
        assert.deepEqual(approvalInput('Edit', { file_path: `${theme}/a.css`, old_string: 'a', new_string: 'b' }, theme), {
            file: 'a.css',
            edits: [{ old: 'a', new: 'b' }],
        });
    });

    it('streams text, tools and their results within a turn, and lists what changed', () => {
        const turns = new Turns(theme);
        assert.equal(turns.start(), 1);
        const ev = (e) => turns.translate({ session_id: 's1', parent_tool_use_id: null, ...e });
        assert.deepEqual(ev({ type: 'stream_event', event: { type: 'content_block_delta', delta: { type: 'text_delta', text: 'Hi' } } }), [
            { type: 'text', turn: 1, delta: 'Hi' },
        ]);
        const tool = ev({
            type: 'assistant',
            message: { content: [{ type: 'tool_use', id: 't1', name: 'Edit', input: { file_path: `${theme}/Blocks/Hero/style.css` } }, { type: 'text', text: 'Hi' }] },
        });
        assert.deepEqual(tool, [{ type: 'tool', turn: 1, id: 't1', name: 'Edit', kind: 'edit', target: 'Blocks/Hero/style.css' }]);
        // The same message again (it repeats as it grows) adds nothing.
        assert.deepEqual(ev({ type: 'assistant', message: { content: [{ type: 'tool_use', id: 't1', name: 'Edit', input: {} }] } }), []);
        assert.deepEqual(ev({ type: 'user', message: { content: [{ type: 'tool_result', tool_use_id: 't1' }] } }), [
            { type: 'tool-done', turn: 1, id: 't1', ok: true },
        ]);
        assert.deepEqual(ev({ type: 'result', subtype: 'success', is_error: false }), [
            { type: 'done', turn: 1, outcome: 'ok', message: '', changed: ['Blocks/Hero/style.css'] },
        ]);
        assert.equal(turns.session, 's1');
    });

    it('turns an approval request into a card, and a sub-agent’s events into nothing', () => {
        const turns = new Turns(theme);
        turns.start();
        const [card] = turns.translate({
            type: 'control_request',
            request_id: 'r1',
            request: { subtype: 'can_use_tool', tool_name: 'Bash', input: { command: 'rm -rf public', description: 'Clean' } },
        });
        assert.deepEqual(card, { type: 'approval', turn: 1, id: 'r1', tool: 'Bash', kind: 'run', target: 'rm -rf public', input: { command: 'rm -rf public', description: 'Clean' } });
        assert.deepEqual(turns.translate({ type: 'stream_event', parent_tool_use_id: 't9', event: { type: 'content_block_delta', delta: { type: 'text_delta', text: 'x' } } }), []);
    });

    it('ends a turn as stopped after Stop, as an error on a failed result or a dead process', () => {
        const turns = new Turns(theme);
        turns.start();
        turns.stop();
        assert.equal(turns.translate({ type: 'result', subtype: 'error_during_execution', is_error: true })[0].outcome, 'stopped');
        turns.start();
        const [failed] = turns.translate({ type: 'result', subtype: 'error_max_turns', is_error: true, errors: ['Too many turns'] });
        assert.equal(failed.outcome, 'error');
        assert.equal(failed.message, 'Too many turns');
        turns.start();
        const [dead] = turns.exited('…\nfatal: dropped');
        assert.deepEqual([dead.outcome, dead.message, dead.detail], ['error', 'agent-exit', '…\nfatal: dropped']);
        assert.deepEqual(turns.exited(), [], 'nothing to end between turns');
    });
});

describe('the theme lock', () => {
    it('finds claude processes working in the theme or inside it', () => {
        const lsof = 'p101\nfcwd\nn/sites/acme/theme\np102\nfcwd\nn/sites/acme/theme/Blocks\np103\nfcwd\nn/sites/other\np104\nfcwd\nn/sites/acme/theme-two';
        assert.deepEqual(pidsIn(lsof, '/sites/acme/theme', (p) => p), [101, 102]);
    });

    it('recognises Claude Code by its command line, whatever the binary is called', () => {
        const ps = [
            '  101 /Users/jane/.local/bin/claude',
            '  102 claude --resume abc',
            '  103 /Users/jane/.local/share/claude/versions/2.1.319 -p --input-format stream-json',
            '  104 /Users/jane/.vscode/extensions/anthropic.claude-code-2.1.318-darwin-arm64/resources/native-binary/claude --output-format stream-json',
            '  105 node /usr/local/lib/node_modules/@anthropic-ai/claude-code/cli.js',
            '  106 /Applications/Claude.app/Contents/MacOS/Claude',
            '  107 vim notes/claude.md',
            '  108 /bin/zsh -c claude-ish',
        ].join('\n');
        assert.deepEqual(claudePids(ps), [101, 102, 103, 104, 105]);
    });

    it('keeps one run file per theme, private and ignored by git, removed only by its owner', () => {
        const theme = mkdtempSync(join(tmpdir(), 'lens-run-'));
        try {
            runfile.write(theme, { pid: process.pid, port: 1, token: 't' });
            assert.equal(runfile.current(theme)?.port, 1);
            assert.equal(statSync(runfile.runPath(theme)).mode & 0o777, 0o600);
            assert.match(readFileSync(join(theme, '.taw/lens/.gitignore'), 'utf8'), /^\*$/m);
            runfile.remove(theme, process.pid + 1);
            assert.ok(runfile.current(theme), 'another process can’t remove it');
            runfile.remove(theme);
            assert.equal(runfile.current(theme), null);
            writeFileSync(runfile.runPath(theme), JSON.stringify({ pid: 999_999_999, port: 2 }));
            assert.equal(runfile.current(theme), null, 'a dead process’s file doesn’t count');
        } finally {
            rmSync(theme, { recursive: true, force: true });
        }
    });
});

describe('starting the agent', () => {
    it('never loads the developer’s MCP servers and names its tools', () => {
        const args = agentArgs({ prompt: 'P' });
        assert.ok(args.includes('--strict-mcp-config'));
        assert.equal(args[args.indexOf('--tools') + 1], TOOLS.join(','));
        assert.ok(!TOOLS.includes('WebFetch') && !TOOLS.includes('Task'));
        assert.equal(args[args.indexOf('--append-system-prompt') + 1], 'P');
        assert.ok(!args.includes('--resume'));
        assert.deepEqual(agentArgs({ resume: 's', model: 'haiku' }).slice(-4), ['--model', 'haiku', '--resume', 's']);
    });

    it('reads its command line', () => {
        assert.deepEqual(parseArgs(['--theme', '/t', '--origin', 'https://a', '--origin', 'http://a', '--resume']), {
            origin: ['https://a', 'http://a'],
            theme: '/t',
            resume: 'true',
        });
    });
});

// ---- The bridge over HTTP, with the fake CLI ----

let theme;
let bridge;
let server;
let port;
let busy = null;
let argsFile;

function call(method, path, { origin = ORIGIN, host, token = TOKEN, body } = {}) {
    return new Promise((resolve, reject) => {
        const headers = { host: host ?? `127.0.0.1:${port}` };
        if (origin) headers.origin = origin;
        if (token) headers['x-lens-token'] = token;
        if (body !== undefined) headers['content-type'] = 'application/json';
        const req = request({ host: '127.0.0.1', port, method, path, headers }, (res) => {
            let data = '';
            res.on('data', (c) => (data += c));
            res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: data ? JSON.parse(data) : null }));
        });
        req.on('error', reject);
        req.end(body === undefined ? undefined : JSON.stringify(body));
    });
}

/** Opens the event stream; `next(match)` waits for the next event that matches. */
function stream() {
    return new Promise((resolve, reject) => {
        const events = [];
        const waiters = [];
        const req = request(
            { host: '127.0.0.1', port, path: `/events?token=${TOKEN}`, headers: { host: `127.0.0.1:${port}`, origin: ORIGIN } },
            (res) => {
                let buffer = '';
                res.setEncoding('utf8');
                res.on('data', (chunk) => {
                    buffer += chunk;
                    let end;
                    while ((end = buffer.indexOf('\n\n')) >= 0) {
                        const block = buffer.slice(0, end);
                        buffer = buffer.slice(end + 2);
                        if (!block.startsWith('data: ')) continue;
                        const ev = JSON.parse(block.slice(6));
                        events.push(ev);
                        for (const w of [...waiters]) {
                            if (w.match(ev)) {
                                waiters.splice(waiters.indexOf(w), 1);
                                w.resolve(ev);
                            }
                        }
                    }
                });
                resolve({
                    events,
                    close: () => req.destroy(),
                    next: (match, ms = 5000) =>
                        new Promise((ok, fail) => {
                            const seen = events.find((e) => match(e) && !e.__taken);
                            if (seen) {
                                seen.__taken = true;
                                ok(seen);
                                return;
                            }
                            const w = { match, resolve: (e) => ((e.__taken = true), ok(e)) };
                            waiters.push(w);
                            setTimeout(() => fail(new Error('no matching event')), ms).unref();
                        }),
                });
            },
        );
        req.on('error', reject);
        req.end();
    });
}

describe('the bridge', () => {
    beforeEach(async () => {
        theme = realpathSync(mkdtempSync(join(tmpdir(), 'lens-theme-')));
        mkdirSync(join(theme, 'Blocks/Hero'), { recursive: true });
        argsFile = join(theme, 'args.json');
        process.env.FAKE_CLAUDE_ARGS = argsFile;
        busy = null;
        bridge = new Bridge({ theme, claude: FAKE, prompt: 'Lens prompt', busyCheck: async () => busy });
        ({ server, port } = await serve(bridge, { token: TOKEN, origins: [ORIGIN] }));
        bridge.start();
    });

    afterEach(async () => {
        bridge.close();
        await new Promise((r) => server.close(r));
        rmSync(theme, { recursive: true, force: true });
    });

    after(() => delete process.env.FAKE_CLAUDE_ARGS);

    it('refuses a foreign origin, a rebound host name and a missing token', async () => {
        assert.equal((await call('GET', '/events', { origin: null })).status, 403);
        assert.equal((await call('GET', '/events', { origin: 'https://evil.example' })).status, 403);
        assert.equal((await call('GET', '/events', { host: `evil.example:${port}` })).status, 403);
        assert.equal((await call('POST', '/message', { token: null, body: { text: 'hi' } })).status, 401);
        assert.equal((await call('POST', '/message', { token: 'b'.repeat(48), body: { text: 'hi' } })).status, 401);
        const preflight = await call('OPTIONS', '/message', { token: null });
        assert.equal(preflight.status, 204);
        assert.equal(preflight.headers['access-control-allow-origin'], ORIGIN);
        assert.equal(preflight.headers['access-control-allow-private-network'], 'true');
    });

    it('runs the developer’s CLI in the theme folder, with Lens’s prompt and no MCP servers', async () => {
        const events = await stream();
        await call('POST', '/message', { body: { text: 'hi' } });
        await events.next((e) => e.type === 'done');
        const recorded = JSON.parse(readFileSync(argsFile, 'utf8'));
        assert.equal(recorded.cwd, theme);
        assert.ok(recorded.args.includes('--strict-mcp-config'));
        assert.equal(recorded.args[recorded.args.indexOf('--append-system-prompt') + 1], 'Lens prompt');
        events.close();
    });

    it('streams a reply, and replays the session to a page that connects later', async () => {
        const events = await stream();
        assert.equal((await events.next((e) => e.type === 'hello')).working, false);
        const sent = await call('POST', '/message', { body: { text: 'hi', context: '<lens-selections>…</lens-selections>', chips: ['hero_title · field'] } });
        assert.deepEqual([sent.status, sent.body], [202, { turn: 1 }]);
        assert.deepEqual(await events.next((e) => e.type === 'turn'), { type: 'turn', turn: 1, text: 'hi', chips: ['hero_title · field'], __taken: true });
        assert.equal((await events.next((e) => e.type === 'done')).outcome, 'ok');
        assert.equal(events.events.filter((e) => e.type === 'text').map((e) => e.delta).join(''), 'Hello there.');
        events.close();

        const later = await stream();
        const hello = await later.next((e) => e.type === 'hello');
        assert.equal(hello.session, 'fake-session-1');
        assert.deepEqual(
            hello.history.map((e) => e.type),
            ['turn', 'text', 'done'],
        );
        assert.equal(hello.history[1].delta, 'Hello there.', 'streamed text is kept as one piece');
        later.close();
    });

    it('marks a message the page wrote for an edit on the page, and only that', async () => {
        const events = await stream();
        await call('POST', '/message', { body: { text: 'Change "A" to "B".', chips: ['“A” · template text'], from: 'edit' } });
        assert.equal((await events.next((e) => e.type === 'turn')).from, 'edit');
        await events.next((e) => e.type === 'done');
        await call('POST', '/message', { body: { text: 'hi', from: 'anything else' } });
        const plain = await events.next((e) => e.type === 'turn' && e.turn === 2);
        assert.equal('from' in plain, false);
        events.close();
    });

    it('waits for the developer’s OK before a change, and lists what changed', async () => {
        const events = await stream();
        await call('POST', '/message', { body: { text: 'edit the hero' } });
        const card = await events.next((e) => e.type === 'approval');
        assert.equal(card.input.file, 'Blocks/Hero/style.css');
        assert.equal((await call('POST', '/message', { body: { text: 'again' } })).status, 409, 'one turn at a time');
        assert.equal((await call('POST', '/approve', { body: { id: card.id, allow: true } })).status, 204);
        assert.equal((await call('POST', '/approve', { body: { id: card.id, allow: true } })).status, 404, 'answered once');
        const done = await events.next((e) => e.type === 'done');
        assert.deepEqual(done.changed, ['Blocks/Hero/style.css']);
        events.close();
    });

    it('tells Claude when the developer skips a change', async () => {
        const events = await stream();
        await call('POST', '/message', { body: { text: 'edit it' } });
        const card = await events.next((e) => e.type === 'approval');
        await call('POST', '/approve', { body: { id: card.id, allow: false } });
        assert.deepEqual(await events.next((e) => e.type === 'approval-done'), { type: 'approval-done', id: card.id, allow: false, __taken: true });
        assert.deepEqual((await events.next((e) => e.type === 'done')).changed, []);
        events.close();
    });

    it('writes nothing while another session holds the theme, until the developer takes over', async () => {
        busy = { pids: [4242], since: '2026-11-07T16:31:00.000Z' };
        const events = await stream();
        await bridge.refreshBusy();
        assert.deepEqual((await events.next((e) => e.type === 'busy')).busy, busy);
        assert.equal(bridge.guard(), BUSY_REASON);
        await call('POST', '/message', { body: { text: 'edit it' } });
        const refused = await events.next((e) => e.type === 'tool-done');
        assert.equal(refused.ok, false);
        assert.ok(!events.events.some((e) => e.type === 'approval'), 'refused before any approval');
        await events.next((e) => e.type === 'done');

        await call('POST', '/take-over', { body: {} });
        assert.equal((await events.next((e) => e.type === 'busy' && e.takenOver)).takenOver, true);
        assert.equal(bridge.guard(), null);
        busy = { pids: [4242, 5151], since: busy.since };
        await bridge.refreshBusy();
        assert.equal(bridge.guard(), BUSY_REASON, 'a new session starts the lock again');
        busy = null;
        await bridge.refreshBusy();
        assert.equal((await events.next((e) => e.type === 'busy' && e.busy === null)).takenOver, false);
        events.close();
    });

    it('stops a turn on Stop', async () => {
        const events = await stream();
        await call('POST', '/message', { body: { text: 'slow' } });
        await events.next((e) => e.type === 'text');
        await call('POST', '/stop', { body: {} });
        assert.equal((await events.next((e) => e.type === 'done')).outcome, 'stopped');
        events.close();
    });

    it('reports a crashed agent mid-turn, then resumes the same session on the next message', async () => {
        const events = await stream();
        await call('POST', '/message', { body: { text: 'crash' } });
        const done = await events.next((e) => e.type === 'done');
        assert.deepEqual([done.outcome, done.message], ['error', 'agent-exit']);
        assert.match(done.detail, /connection dropped/);
        await call('POST', '/message', { body: { text: 'hi again' } });
        assert.equal((await events.next((e) => e.type === 'done')).outcome, 'ok');
        const recorded = JSON.parse(readFileSync(argsFile, 'utf8')).args;
        assert.equal(recorded[recorded.indexOf('--resume') + 1], 'fake-session-1');
        events.close();
    });

    it('refuses an empty or oversized message', async () => {
        assert.equal((await call('POST', '/message', { body: { text: '  ' } })).status, 422);
        assert.equal((await call('POST', '/message', { body: { text: 'x'.repeat(20_001) } })).status, 422);
        assert.equal((await call('POST', '/nope', { body: {} })).status, 404);
    });
});

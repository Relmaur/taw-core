// Tests for the TAW Lens bridge (resources/lens-bridge/), against a fake `claude` (fixtures/fake-claude.mjs).
// Run: node --test tests/js/
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readFileSync, realpathSync, rmSync, statSync, utimesSync, writeFileSync } from 'node:fs';
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
import * as transcripts from '../../resources/lens-bridge/lib/transcript.mjs';
import { approvalInput, relPath, toolKind, toolTarget, Turns } from '../../resources/lens-bridge/lib/translate.mjs';

const FAKE = fileURLToPath(new URL('./fixtures/fake-claude.mjs', import.meta.url));
const ORIGIN = 'https://acme.local';
const TOKEN = 'a'.repeat(48);
const NOW = '2026-11-08T10:12:00.000Z';

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
        delete process.env.FAKE_CLAUDE_SESSION;
        busy = null;
        bridge = new Bridge({ theme, claude: FAKE, prompt: 'Lens prompt', busyCheck: async () => busy, now: () => NOW });
        ({ server, port } = await serve(bridge, { token: TOKEN, origins: [ORIGIN] }));
        bridge.start();
    });

    afterEach(async () => {
        bridge.close();
        await new Promise((r) => server.close(r));
        rmSync(theme, { recursive: true, force: true });
    });

    after(() => {
        delete process.env.FAKE_CLAUDE_ARGS;
        delete process.env.FAKE_CLAUDE_SESSION;
    });

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
        assert.deepEqual(await events.next((e) => e.type === 'turn'), { type: 'turn', turn: 1, text: 'hi', chips: ['hero_title · field'], at: NOW, __taken: true });
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

    it('keeps each conversation in a transcript, and replays it after a restart with --resume', async () => {
        const events = await stream();
        await call('POST', '/message', { body: { text: 'Make the hero smaller.\nOn phones.', chips: ['hero_title · field'], page: '/about/' } });
        await events.next((e) => e.type === 'done');
        assert.equal(events.events.find((e) => e.type === 'turn').page, '/about/');
        const listed = await events.next((e) => e.type === 'conversations' && e.conversations[0]?.changes === 0 && e.conversations[0].id !== '');
        assert.deepEqual(listed.conversations, [
            { id: 'fake-session-1', title: 'Make the hero smaller.', at: NOW, changes: 0, current: true },
        ]);
        events.close();
        bridge.close();
        await new Promise((r) => server.close(r));

        bridge = new Bridge({ theme, claude: FAKE, prompt: 'Lens prompt', busyCheck: async () => busy, now: () => NOW, resume: 'fake-session-1' });
        ({ server, port } = await serve(bridge, { token: TOKEN, origins: [ORIGIN] }));
        const later = await stream();
        const hello = await later.next((e) => e.type === 'hello');
        assert.deepEqual(hello.history.map((e) => e.type), ['turn', 'text', 'done', 'resumed']);
        assert.equal(hello.history[1].delta, 'Hello there.', 'one line of text, not one per delta');
        const sent = await call('POST', '/message', { body: { text: 'Again' } });
        assert.deepEqual(sent.body, { turn: 2 }, 'turns go on counting');
        await later.next((e) => e.type === 'done');
        const args = JSON.parse(readFileSync(argsFile, 'utf8')).args;
        assert.equal(args[args.indexOf('--resume') + 1], 'fake-session-1');
        later.close();
    });

    it('starts a new conversation and reopens an earlier one from the menu', async () => {
        const events = await stream();
        await events.next((e) => e.type === 'hello');
        await call('POST', '/message', { body: { text: 'First conversation' } });
        await events.next((e) => e.type === 'done');

        process.env.FAKE_CLAUDE_SESSION = 'fake-session-2';
        assert.equal((await call('POST', '/conversation', { body: { session: null } })).status, 204);
        const fresh = await events.next((e) => e.type === 'hello' && e.history.length === 0);
        assert.deepEqual(fresh.conversations.map((c) => [c.id, c.current]), [['fake-session-1', false]]);
        await call('POST', '/message', { body: { text: 'Second conversation' } });
        await events.next((e) => e.type === 'done');
        assert.equal(JSON.parse(readFileSync(argsFile, 'utf8')).args.includes('--resume'), false, 'a new session');

        assert.equal((await call('POST', '/conversation', { body: { session: 'fake-session-1' } })).status, 204);
        const reopened = await events.next((e) => e.type === 'hello' && e.history[0]?.text === 'First conversation');
        assert.equal(reopened.history.at(-1).type, 'resumed');
        assert.deepEqual(
            reopened.conversations.map((c) => [c.title, c.current]),
            [['First conversation', true], ['Second conversation', false]],
        );
        await call('POST', '/message', { body: { text: 'hi' } });
        await events.next((e) => e.type === 'done' && e.turn === 2);
        const args = JSON.parse(readFileSync(argsFile, 'utf8')).args;
        assert.equal(args[args.indexOf('--resume') + 1], 'fake-session-1');
        events.close();
    });

    it('won’t switch while Claude works, nor to a conversation it doesn’t know', async () => {
        const events = await stream();
        await call('POST', '/message', { body: { text: 'slow' } });
        await events.next((e) => e.type === 'text');
        assert.deepEqual(await call('POST', '/conversation', { body: { session: null } }), {
            status: 409, headers: (await call('POST', '/conversation', { body: { session: null } })).headers, body: { error: 'working' },
        });
        await call('POST', '/stop', { body: {} });
        await events.next((e) => e.type === 'done');
        assert.equal((await call('POST', '/conversation', { body: { session: '../../etc' } })).status, 422);
        assert.equal((await call('POST', '/conversation', { body: { session: 'no-such-session' } })).status, 404);
        events.close();
    });

    it('records fields saved from the page in the conversation, and counts them as changes', async () => {
        const events = await stream();
        await call('POST', '/message', { body: { text: 'hi' } });
        await events.next((e) => e.type === 'done');
        assert.equal((await call('POST', '/note', { body: { id: 'n1', name: 'hero_title' } })).status, 204);
        assert.deepEqual(await events.next((e) => e.type === 'saved'), { type: 'saved', id: 'n1', name: 'hero_title', at: NOW, __taken: true });
        const counted = await events.next((e) => e.type === 'conversations' && e.conversations[0].changes === 1);
        assert.equal(counted.conversations[0].title, 'hi');
        await call('POST', '/note', { body: { id: 'n1', name: 'hero_title', undone: true } });
        await events.next((e) => e.type === 'conversations' && e.conversations[0].changes === 0);
        assert.equal((await call('POST', '/note', { body: { id: '../x', name: 'hero_title' } })).status, 422);
        events.close();

        const { events: kept } = transcripts.load(theme, 'fake-session-1');
        assert.deepEqual(kept.filter((e) => e.type === 'saved'), [{ type: 'saved', id: 'n1', name: 'hero_title', at: NOW, undone: true }]);
    });
});

describe('transcripts', () => {
    let dir;
    beforeEach(() => (dir = realpathSync(mkdtempSync(join(tmpdir(), 'lens-transcripts-')))));
    afterEach(() => rmSync(dir, { recursive: true, force: true }));

    const write = (id, events, mtime) => {
        const t = new transcripts.Transcript(dir, null);
        t.named(id);
        events.forEach((e) => t.record(e));
        t.close();
        if (mtime) {
            const at = new Date(mtime);
            utimesSync(join(transcripts.folder(dir), `${id}.jsonl`), at, at);
        }
    };

    it('titles a conversation by its first message and counts each change once', () => {
        assert.equal(transcripts.titleOf('\n  Make it smaller  \nmore'), 'Make it smaller');
        assert.equal(transcripts.titleOf('x'.repeat(100)).length, 80);
        assert.deepEqual(
            transcripts.summary([
                { type: 'turn', turn: 1, text: 'One', at: 'a' },
                { type: 'done', turn: 1, changed: ['style.css', 'Hero.php'], at: 'b' },
                { type: 'turn', turn: 2, text: 'Two', at: 'c' },
                { type: 'done', turn: 2, changed: ['style.css'], at: 'd' },
                { type: 'saved', id: '1', name: 'hero_title', at: 'e' },
                { type: 'saved', id: '2', name: 'hero_intro', undone: true, at: 'f' },
            ]),
            { title: 'One', at: 'f', changes: 3 },
        );
    });

    it('waits for the session id, writes in order, and joins streamed text', () => {
        const t = new transcripts.Transcript(dir, null);
        t.record({ type: 'turn', turn: 1, text: 'hi' });
        t.record({ type: 'text', turn: 1, delta: 'Hel' });
        t.named('session-1');
        t.record({ type: 'text', turn: 1, delta: 'lo' });
        t.record({ type: 'done', turn: 1, outcome: 'ok', message: '', changed: [] });
        assert.deepEqual(transcripts.load(dir, 'session-1').events, [
            { type: 'turn', turn: 1, text: 'hi' },
            { type: 'text', turn: 1, delta: 'Hello' },
            { type: 'done', turn: 1, outcome: 'ok', message: '', changed: [] },
        ]);
    });

    it('ends a turn the bridge stopped in, and folds a save and its undo into one line', () => {
        assert.deepEqual(
            transcripts.settle([
                { type: 'turn', turn: 1, text: 'hi' },
                { type: 'saved', id: 'a', name: 'hero_title' },
                { type: 'saved', id: 'a', name: 'hero_title', undone: true },
            ]),
            [
                { type: 'turn', turn: 1, text: 'hi' },
                { type: 'saved', id: 'a', name: 'hero_title', undone: true },
                { type: 'done', turn: 1, outcome: 'stopped', message: '', changed: [] },
            ],
        );
    });

    it('follows a resumed session that came back under another id', () => {
        const t = new transcripts.Transcript(dir, null);
        t.named('first-id');
        t.record({ type: 'turn', turn: 1, text: 'hi' });
        t.named('second-id');
        assert.equal(transcripts.load(dir, 'first-id').session, 'second-id');
        assert.equal(transcripts.find(dir, 'second-id'), 'first-id');
        assert.equal(transcripts.find(dir, 'unknown-id'), null);
    });

    it('lists the newest conversations with something said, and keeps only the newest on disk', () => {
        write('old-one', [{ type: 'turn', turn: 1, text: 'Old' }], 1_000_000);
        write('new-one', [{ type: 'turn', turn: 1, text: 'New' }], 2_000_000);
        write('silent', [{ type: 'busy' }], 3_000_000);
        assert.deepEqual(transcripts.list(dir).map((c) => c.id), ['new-one', 'old-one']);
        assert.deepEqual(transcripts.list(dir, 1).map((c) => c.id), ['new-one']);
        transcripts.prune(dir, 2);
        assert.deepEqual(transcripts.ids(dir), ['silent', 'new-one']);
    });

    it('never reads or writes outside its folder', () => {
        for (const id of ['../x', 'a/b', '', '.hidden', 'x'.repeat(65)]) {
            assert.equal(transcripts.validId(id), false, id);
            assert.deepEqual(transcripts.load(dir, id), { events: [], session: null });
        }
    });
});


// Contract test: the Lens bridge against the developer's real `claude` CLI (umbrella ADR-0006 § 8 accepts the
// stream-json control protocol on the condition that this test exists). It spends a few cents on a small
// model, so it runs only when asked:
//
//   TAW_LENS_CONTRACT=1 node --test tests/js/lens-bridge-contract.test.mjs
//
// Optional: TAW_LENS_CLAUDE (the CLI's path), TAW_LENS_MODEL (default haiku).
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, readFileSync, realpathSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, it } from 'node:test';

import { Bridge } from '../../resources/lens-bridge/lib/bridge.mjs';

const enabled = process.env.TAW_LENS_CONTRACT === '1';

/** Collects the bridge's events and waits for one. */
function watch(bridge) {
    const events = [];
    const waiters = [];
    bridge.clients.add({
        write(line) {
            if (!line.startsWith('data: ')) return;
            const ev = JSON.parse(line.slice(6));
            events.push(ev);
            for (const w of [...waiters]) if (w.match(ev)) (waiters.splice(waiters.indexOf(w), 1), w.resolve(ev));
        },
        end() {},
    });
    return {
        events,
        next: (match, ms = 120_000) =>
            new Promise((resolve, reject) => {
                waiters.push({ match, resolve });
                setTimeout(() => reject(new Error(`timed out; saw ${events.map((e) => e.type).join(',')}`)), ms).unref();
            }),
    };
}

describe('the real claude CLI', { skip: !enabled && 'set TAW_LENS_CONTRACT=1 to run' }, () => {
    const make = (busy = null) => {
        const theme = realpathSync(mkdtempSync(join(tmpdir(), 'lens-contract-')));
        const bridge = new Bridge({
            theme,
            claude: process.env.TAW_LENS_CLAUDE ?? 'claude',
            model: process.env.TAW_LENS_MODEL ?? 'haiku',
            // Whatever the developer's own mode, this test needs the approval path.
            permissionMode: 'default',
            prompt: 'You are in a test. Do exactly what is asked, with the tool named, and nothing else.',
            busyCheck: async () => busy,
        });
        return { theme, bridge };
    };

    it('asks before a write, streams the reply, resumes after a restart', { timeout: 300_000 }, async () => {
        const { theme, bridge } = make();
        try {
            const w = watch(bridge);
            bridge.start();
            bridge.message({ text: 'Use the Write tool to create lens-contract.txt containing exactly: ok. Then reply "written".' });
            const card = await w.next((e) => e.type === 'approval');
            assert.equal(card.tool, 'Write');
            assert.equal(card.input.file, 'lens-contract.txt');
            bridge.approve(card.id, true);
            const done = await w.next((e) => e.type === 'done');
            assert.equal(done.outcome, 'ok', done.message);
            assert.deepEqual(done.changed, ['lens-contract.txt']);
            assert.equal(readFileSync(join(theme, 'lens-contract.txt'), 'utf8').trim(), 'ok');
            assert.ok(w.events.some((e) => e.type === 'text'), 'the reply streamed');
            const session = bridge.session;
            assert.ok(session);

            // Stop mid-reply; the session survives.
            bridge.message({ text: 'Count from 1 to 400, one number per line, no tools.' });
            await w.next((e) => e.type === 'text');
            bridge.stop();
            assert.equal((await w.next((e) => e.type === 'done')).outcome, 'stopped');

            // A new process resumes the same conversation.
            bridge.agent.close();
            await new Promise((r) => bridge.agent.once('exit', r));
            bridge.message({ text: 'What file did you create earlier? Reply with the file name only.' });
            const resumed = await w.next((e) => e.type === 'done');
            assert.equal(resumed.outcome, 'ok', resumed.message);
            const reply = w.events.filter((e) => e.type === 'text' && e.turn === 3).map((e) => e.delta).join('');
            assert.match(reply, /lens-contract\.txt/);
            assert.equal(bridge.session, session);
        } finally {
            bridge.close();
            rmSync(theme, { recursive: true, force: true });
        }
    });

    it('refuses writes while another session holds the theme', { timeout: 180_000 }, async () => {
        const { theme, bridge } = make({ pids: [1], since: null });
        try {
            const w = watch(bridge);
            await bridge.refreshBusy();
            bridge.start();
            bridge.message({ text: 'Use the Write tool to create lens-busy.txt containing: x. If refused, reply "refused".' });
            const done = await w.next((e) => e.type === 'done');
            assert.equal(done.outcome, 'ok', done.message);
            assert.deepEqual(done.changed, []);
            assert.ok(!w.events.some((e) => e.type === 'approval'), 'the lock refused it before any approval');
            assert.ok(!existsSync(join(theme, 'lens-busy.txt')));
        } finally {
            bridge.close();
            rmSync(theme, { recursive: true, force: true });
        }
    });
});

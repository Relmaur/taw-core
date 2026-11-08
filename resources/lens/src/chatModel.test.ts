import { describe, expect, it } from 'vitest';
import {
    DROPPED,
    dropped,
    emptyChat,
    markApproval,
    pendingApproval,
    reduce,
    type BridgeEvent,
    type Chat,
} from './chatModel';

const session: BridgeEvent[] = [
    { type: 'turn', turn: 1, text: 'Shorten the headline', chips: ['hero_title · field'] },
    { type: 'text', turn: 1, delta: 'Looking ' },
    { type: 'text', turn: 1, delta: 'at it.' },
    { type: 'tool', turn: 1, id: 't1', name: 'Read', kind: 'read', target: 'Blocks/Hero/Hero.php' },
    { type: 'tool', turn: 1, id: 't2', name: 'Read', kind: 'read', target: 'Blocks/Hero/index.php' },
    { type: 'tool-done', turn: 1, id: 't1', ok: true },
    { type: 'tool-done', turn: 1, id: 't2', ok: true },
    {
        type: 'approval',
        turn: 1,
        id: 'r1',
        tool: 'Edit',
        kind: 'edit',
        target: 'Blocks/Hero/style.css',
        input: { file: 'Blocks/Hero/style.css', edits: [{ old: 'a', new: 'b' }] },
    },
    { type: 'approval-done', id: 'r1', allow: true },
    { type: 'text', turn: 1, delta: 'Done.' },
    { type: 'done', turn: 1, outcome: 'ok', message: '', changed: ['Blocks/Hero/style.css'] },
];

const play = (events: BridgeEvent[], from: Chat = emptyChat) => events.reduce(reduce, from);

describe('the conversation', () => {
    it('builds turns from the stream: text joined, steps grouped between texts, approvals in place', () => {
        const chat = play(session);
        expect(chat.working).toBe(false);
        const [turn] = chat.turns;
        expect(turn).toMatchObject({
            turn: 1,
            text: 'Shorten the headline',
            chips: ['hero_title · field'],
            outcome: 'ok',
        });
        expect(turn.items.map((i) => i.type)).toEqual(['text', 'steps', 'approval', 'text']);
        expect(turn.items[0]).toEqual({ type: 'text', text: 'Looking at it.' });
        expect(turn.items[1]).toMatchObject({
            steps: [
                { id: 't1', state: 'done' },
                { id: 't2', state: 'done' },
            ],
        });
        expect(turn.items[2]).toMatchObject({ id: 'r1', state: 'applied' });
        expect(turn.changed).toEqual(['Blocks/Hero/style.css']);
    });

    it('is working from a message until its turn ends', () => {
        expect(play(session.slice(0, 4)).working).toBe(true);
        expect(pendingApproval(play(session.slice(0, 8)))).toMatchObject({ id: 'r1', state: 'pending' });
        expect(pendingApproval(play(session))).toBeNull();
    });

    it('ends up the same after a reload replays the history as it did live', () => {
        const live = play(session);
        const reloaded = reduce(emptyChat, {
            type: 'hello',
            protocol: 1,
            session: 's1',
            working: false,
            busy: null,
            takenOver: false,
            history: session,
        });
        expect(reloaded.turns).toEqual(live.turns);
        expect(reloaded.session).toBe('s1');
    });

    it('closes what a turn left open when it ends: running steps failed, unanswered cards skipped', () => {
        const chat = play([
            ...session.slice(0, 5),
            {
                type: 'approval',
                turn: 1,
                id: 'r2',
                tool: 'Bash',
                kind: 'run',
                target: 'npm run build',
                input: { command: 'npm run build' },
            },
            { type: 'done', turn: 1, outcome: 'stopped', message: '', changed: [] },
        ]);
        expect(chat.turns[0].items[1]).toMatchObject({ steps: [{ state: 'failed' }, { state: 'failed' }] });
        expect(chat.turns[0].items[2]).toMatchObject({ id: 'r2', state: 'skipped' });
        expect(chat.turns[0].outcome).toBe('stopped');
    });

    it('marks a turn the connection dropped in the middle of', () => {
        const chat = dropped(play(session.slice(0, 4)));
        expect(chat.working).toBe(false);
        expect(chat.turns[0]).toMatchObject({ outcome: 'error', message: DROPPED });
        expect(dropped(play(session))).toEqual({ ...play(session), working: false });
    });

    it('tracks an answer in flight, and puts it back if sending failed', () => {
        const waiting = play(session.slice(0, 8));
        expect(pendingApproval(markApproval(waiting, 'r1', 'sending'))?.state).toBe('sending');
        expect(pendingApproval(markApproval(markApproval(waiting, 'r1', 'sending'), 'r1', 'pending'))?.state).toBe(
            'pending',
        );
    });

    it('knows when another session holds the theme', () => {
        const busy = { pids: [42], since: '2026-11-07T16:31:00Z' };
        expect(reduce(emptyChat, { type: 'busy', busy, takenOver: false })).toMatchObject({ busy, takenOver: false });
        expect(reduce(emptyChat, { type: 'busy', busy, takenOver: true }).takenOver).toBe(true);
    });

    it('ignores events for a turn it never saw', () => {
        expect(reduce(emptyChat, { type: 'text', turn: 9, delta: 'x' })).toBe(emptyChat);
    });
});

import { act, fireEvent, render, screen, within } from '@testing-library/preact';
import { describe, expect, it, vi } from 'vitest';
import type { BridgeClient, BridgeHandlers, Connection } from './bridge';
import type { BridgeEvent } from './chatModel';
import { readManifest } from './manifest';
import type { PickHandlers } from './page';
import { Panel, type PageEnv, type PanelEnv } from './Panel';
import { DEFAULTS } from './prefs';
import { PageMap } from './sourcemap';
import { $, mountFixture } from './testing';

/** A panel on the fixture page, with a bridge the test drives. */
function setup() {
    mountFixture();
    let handlers: BridgeHandlers | null = null;
    let picker: PickHandlers | null = null;
    const answer = { ok: true, status: 202 };
    const send = vi.fn<BridgeClient['send']>(async () => answer);
    const close = vi.fn();
    const page: PageEnv = {
        map: vi.fn(() => new PageMap(document, readManifest(document)!)),
        pick: vi.fn((h: PickHandlers) => {
            picker = h;
            return () => {};
        }),
        sampler: null,
        onAsset: () => () => {},
        reveal: vi.fn(),
        url: 'https://acme.local/lens-test/',
        editor: 'vscode://file/{path}:{line}',
    };
    const env: PanelEnv = {
        viewport: () => ({ width: 1440, height: 900, top: 0 }),
        push: vi.fn(),
        theme: vi.fn(),
        save: vi.fn(),
        onResize: () => () => {},
        page,
        bridge: (h) => {
            handlers = h;
            return { send, close };
        },
    };
    const view = render(<Panel host="taw.local" initial={DEFAULTS} env={env} />);
    const status = (c: Connection) => act(() => handlers!.status(c));
    const emit = (...events: BridgeEvent[]) => act(() => events.forEach((e) => handlers!.event(e)));
    const connect = (history: BridgeEvent[] = [], extra: Partial<Extract<BridgeEvent, { type: 'hello' }>> = {}) => {
        status('connected');
        emit({
            type: 'hello',
            protocol: 1,
            session: 's1',
            working: false,
            busy: null,
            takenOver: false,
            history,
            ...extra,
        });
    };
    const pick = (selector: string, add = false) => {
        fireEvent.click(screen.getByRole('button', { name: 'Pick an element' }));
        act(() => picker!.pick($(selector), add));
        act(() => picker!.stop());
    };
    const type = (text: string) => fireEvent.input(screen.getByLabelText('Message'), { target: { value: text } });
    const enter = () => fireEvent.keyDown(screen.getByLabelText('Message'), { key: 'Enter' });

    return { view, send, close, answer, status, emit, connect, pick, type, enter };
}

const flush = () => act(() => new Promise((r) => setTimeout(r, 0)));

describe('connecting (screen 07)', () => {
    it('shows "Connecting…" and skeleton lines while it finds the bridge', () => {
        setup();
        expect(screen.getByText('Connecting…')).toBeInTheDocument();
        expect(document.querySelector('.skeleton')).not.toBeNull();
        expect(screen.getByLabelText('Message')).toBeDisabled();
    });

    it('opens on the first-open state once connected, with the composer live', () => {
        const { connect } = setup();
        connect();
        expect(screen.getByText('Point at anything on the page')).toBeInTheDocument();
        // Round 2: the conversation's title takes the host's place; the host moved to the menu's foot.
        expect(screen.getByRole('button', { name: /^Conversation: New chat\./ })).toBeInTheDocument();
        expect(screen.queryByText('taw.local')).toBeNull();
        expect(screen.queryByText('Connecting…')).toBeNull();
        expect(screen.getByLabelText('Message')).toBeEnabled();
        expect(screen.getByLabelText('Message')).toHaveAttribute('placeholder', 'Tell Claude what to change…');
    });

    it('says so when the running taw lens is another version', () => {
        const { status } = setup();
        status('outdated');
        expect(screen.getByText('Lens needs a restart')).toBeInTheDocument();
        expect(screen.getByText('Restart needed')).toBeInTheDocument();
    });

    it('stops listening when the panel goes away', () => {
        const { view, close } = setup();
        act(() => view.unmount());
        expect(close).toHaveBeenCalled();
    });
});

describe('talking to Claude', () => {
    it('sends the message with what is selected, then shows the chips instead of the card', async () => {
        const { connect, pick, type, enter, send } = setup();
        connect();
        pick('h1');
        expect(screen.getByRole('region', { name: 'Inspect' })).toBeInTheDocument();
        type('Shorten this headline');
        enter();
        await flush();

        expect(send).toHaveBeenCalledTimes(1);
        const [path, body] = send.mock.calls[0] as [string, { text: string; context: string; chips: string[] }];
        expect(path).toBe('/message');
        expect(body.text).toBe('Shorten this headline');
        expect(body.chips).toEqual(['hero_heading · field']);
        expect(body.context).toContain('1. Field hero_heading (Heading, text)');
        expect(body.context).toContain('url="https://acme.local/lens-test/"');
        expect(screen.getByLabelText('Message')).toHaveValue('');
        expect(screen.queryByRole('region', { name: 'Inspect' })).toBeNull();
        expect(screen.getByText('Shift-click to add more')).toBeInTheDocument();
    });

    it('keeps the text when sending fails', async () => {
        const { connect, type, enter, answer } = setup();
        connect();
        answer.ok = false;
        answer.status = 0;
        type('Hello');
        enter();
        await flush();
        expect(screen.getByLabelText('Message')).toHaveValue('Hello');
        expect(screen.getByRole('status')).toHaveTextContent('Lens couldn’t send that');
    });

    it('streams the reply with the activity line; Send becomes Stop while Claude works', async () => {
        const { connect, emit, send } = setup();
        connect();
        emit(
            {
                type: 'turn',
                turn: 1,
                text: 'Make the work grid three columns on desktop.',
                chips: ['Work grid · block'],
            },
            { type: 'tool', turn: 1, id: 't1', name: 'Read', kind: 'read', target: 'Blocks/WorkGrid/WorkGrid.php' },
            { type: 'tool', turn: 1, id: 't2', name: 'Read', kind: 'read', target: 'Blocks/WorkGrid/style.css' },
            { type: 'tool-done', turn: 1, id: 't1', ok: true },
            { type: 'tool-done', turn: 1, id: 't2', ok: true },
            { type: 'tool', turn: 1, id: 't3', name: 'Edit', kind: 'edit', target: 'Blocks/WorkGrid/style.css' },
        );
        const log = screen.getByRole('log', { name: 'Conversation' });
        expect(within(log).getByText('Work grid · block')).toBeInTheDocument();
        expect(within(log).getByText('Make the work grid three columns on desktop.')).toBeInTheDocument();
        expect(log).toHaveTextContent('Read 2 files in Blocks/WorkGrid/');
        expect(log).toHaveTextContent('Editing Blocks/WorkGrid/style.css');
        expect(screen.getByLabelText('Message')).toHaveAttribute('placeholder', 'Claude is working…');

        fireEvent.click(screen.getByRole('button', { name: 'Stop' }));
        await flush();
        expect(send).toHaveBeenLastCalledWith('/stop', {});

        emit(
            { type: 'tool-done', turn: 1, id: 't3', ok: true },
            { type: 'text', turn: 1, delta: 'Done: the grid is ' },
            { type: 'text', turn: 1, delta: 'three columns from `lg` up.' },
            { type: 'done', turn: 1, outcome: 'ok', message: '', changed: ['Blocks/WorkGrid/style.css'] },
        );
        expect(log).toHaveTextContent('Edited Blocks/WorkGrid/style.css');
        expect(log.querySelector('.reply code')).toHaveTextContent('lg');
        expect(screen.getByRole('button', { name: 'Send' })).toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent('Claude finished');
    });

    it('expands the activity into every step under Details', () => {
        const { connect, emit } = setup();
        connect([
            { type: 'turn', turn: 1, text: 'x', chips: [] },
            { type: 'tool', turn: 1, id: 't1', name: 'Read', kind: 'read', target: 'a.php' },
            { type: 'tool', turn: 1, id: 't2', name: 'Read', kind: 'read', target: 'b.php' },
            { type: 'done', turn: 1, outcome: 'ok', message: '', changed: [] },
        ]);
        emit();
        fireEvent.click(screen.getByRole('button', { name: 'Details' }));
        expect(screen.getByText('Read a.php')).toBeInTheDocument();
        expect(screen.getByText('Read b.php')).toBeInTheDocument();
    });

    it('asks for an OK with the diff, and sends the answer (screen 01)', async () => {
        const { connect, emit, send } = setup();
        connect([{ type: 'turn', turn: 1, text: 'Tighten the space', chips: [] }], { working: true });
        emit({
            type: 'approval',
            turn: 1,
            id: 'r1',
            tool: 'Edit',
            kind: 'edit',
            target: 'Blocks/Hero/style.css',
            input: {
                file: 'Blocks/Hero/style.css',
                edits: [
                    {
                        old: '.hero__title {\n  margin-bottom: 3rem;\n}',
                        new: '.hero__title {\n  margin-bottom: 1.5rem;\n}',
                    },
                ],
            },
        });
        const card = screen.getByRole('group', { name: 'Edit 1 file' });
        expect(card).toHaveTextContent('Blocks/Hero/style.css');
        expect(card.querySelector('[data-kind="removed"]')).toHaveTextContent('margin-bottom: 3rem;');
        expect(card.querySelector('[data-kind="added"]')).toHaveTextContent('margin-bottom: 1.5rem;');
        expect(screen.getByRole('status')).toHaveTextContent('Claude needs your OK');

        fireEvent.click(within(card).getByRole('button', { name: 'Apply' }));
        expect(within(card).getByRole('button', { name: 'Apply' })).toBeDisabled();
        await flush();
        expect(send).toHaveBeenLastCalledWith('/approve', { id: 'r1', allow: true });
        emit({ type: 'approval-done', id: 'r1', allow: true });
        expect(screen.queryByRole('group', { name: 'Edit 1 file' })).toBeNull();
        expect(screen.getByText('Blocks/Hero/style.css').closest('.approval-done')).toHaveTextContent(
            'Applied: Blocks/Hero/style.css',
        );
    });

    it('lets the developer skip, and shows a command for a Bash approval', async () => {
        const { connect, emit, send } = setup();
        connect([{ type: 'turn', turn: 1, text: 'Build it', chips: [] }], { working: true });
        emit({
            type: 'approval',
            turn: 1,
            id: 'r2',
            tool: 'Bash',
            kind: 'run',
            target: 'npm run build',
            input: { command: 'npm run build', description: 'Build the theme' },
        });
        const card = screen.getByRole('group', { name: 'Run a command' });
        expect(card).toHaveTextContent('Build the theme');
        expect(card).toHaveTextContent('npm run build');
        fireEvent.click(within(card).getByRole('button', { name: 'Skip' }));
        await flush();
        expect(send).toHaveBeenLastCalledWith('/approve', { id: 'r2', allow: false });
    });
});

describe('when things go wrong (screen 07)', () => {
    const turn: BridgeEvent = {
        type: 'turn',
        turn: 1,
        text: 'Make the work grid three columns on desktop.',
        chips: [],
    };

    it('shows the error in ink, says what changed, and tries again with the same message', async () => {
        const { connect, emit, send, type, enter } = setup();
        connect();
        type('Make the work grid three columns on desktop.');
        enter();
        await flush();
        emit(turn, {
            type: 'done',
            turn: 1,
            outcome: 'error',
            message: 'agent-exit',
            detail: 'fatal: the connection dropped',
            changed: [],
        });
        const alert = screen.getByRole('alert');
        expect(alert).toHaveTextContent('Claude stopped before finishing');
        expect(alert).toHaveTextContent(
            'The connection to Claude dropped. Nothing was changed after the last step you saw.',
        );
        fireEvent.click(within(alert).getByRole('button', { name: 'Details' }));
        expect(alert).toHaveTextContent('fatal: the connection dropped');
        fireEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
        await flush();
        expect(send).toHaveBeenCalledTimes(2);
        expect(send.mock.calls[1]).toEqual(send.mock.calls[0]);
    });

    it('lists the changes made before an error', () => {
        const { connect } = setup();
        connect([
            turn,
            {
                type: 'done',
                turn: 1,
                outcome: 'error',
                message: 'Too many turns',
                changed: ['Blocks/WorkGrid/style.css'],
            },
        ]);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Claude reported an error. It had changed Blocks/WorkGrid/style.css by then.',
        );
    });

    it('ends a turn in progress as dropped when the bridge goes away, and says how to restart', () => {
        const { connect, emit, status } = setup();
        connect();
        emit(turn);
        status('off');
        expect(screen.getByRole('alert')).toHaveTextContent('The connection to Claude dropped.');
        expect(screen.getByText('Not connected')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Try again' })).toBeDisabled();
    });

    it('says "Stopped." after Stop', () => {
        const { connect } = setup();
        connect([turn, { type: 'done', turn: 1, outcome: 'stopped', message: '', changed: [] }]);
        expect(screen.getByText('Stopped.')).toBeInTheDocument();
    });
});

describe('theme busy (screen 07): warn, don’t block', () => {
    const busy = { pids: [4242], since: '2026-11-07T16:31:00' };

    it('shows the amber card; Wait for it folds it to one line', () => {
        const { connect } = setup();
        connect([], { busy });
        const card = screen.getByText('Another Claude session is editing this theme').closest('.busy')!;
        expect(card).toHaveTextContent('started at');
        expect(card).toHaveTextContent('Lens won’t write files until it’s free.');
        fireEvent.click(screen.getByRole('button', { name: 'Wait for it' }));
        expect(
            screen.getByText('Waiting for the other Claude session. Lens writes no files until it’s free.'),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Message')).toBeEnabled();
    });

    it('asks once more before taking over', async () => {
        const { connect, send, emit } = setup();
        connect([], { busy });
        fireEvent.click(screen.getByRole('button', { name: 'Take over' }));
        expect(screen.getByText('Take over the theme?')).toBeInTheDocument();
        expect(send).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Take over' }));
        await flush();
        expect(send).toHaveBeenLastCalledWith('/take-over', {});
        emit({ type: 'busy', busy, takenOver: true });
        expect(screen.getByText(/You took over this theme/)).toBeInTheDocument();
    });
});

describe('conversations (component 06, screen 15)', () => {
    const conversations = [
        { id: 's1', title: 'Make the hero smaller', at: '2026-11-08T10:12:00Z', changes: 2, current: true },
        { id: 's0', title: 'Footer copy for the launch', at: '2026-11-05T11:40:00Z', changes: 0, current: false },
    ];
    const history: BridgeEvent[] = [
        {
            type: 'turn',
            turn: 1,
            text: 'Make the hero smaller',
            chips: ['hero_title · field'],
            at: '2026-11-07T16:31:00Z',
            page: '/lens-test/',
        },
        { type: 'text', turn: 1, delta: 'Done.' },
        { type: 'done', turn: 1, outcome: 'ok', message: '', changed: ['style.css'] },
        { type: 'turn', turn: 2, text: 'Now the grid', chips: ['Work grid · block'], page: '/other-page/' },
        {
            type: 'approval',
            turn: 2,
            id: 'a1',
            tool: 'Edit',
            kind: 'edit',
            target: 'grid.css',
            input: { file: 'grid.css', edits: [{ old: 'a', new: 'b' }] },
        },
        { type: 'approval-done', id: 'a1', allow: true },
        { type: 'done', turn: 2, outcome: 'ok', message: '', changed: ['grid.css'] },
        { type: 'saved', id: 'x-1', name: 'hero_intro' },
        { type: 'resumed', at: '2026-11-08T10:12:00Z' },
    ];

    it('names the conversation in the header and lists the others in its menu, with the host at its foot', () => {
        const { connect } = setup();
        connect([], { conversations });
        const title = screen.getByRole('button', { name: /^Conversation: Make the hero smaller\./ });
        expect(title).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(title);
        const menu = screen.getByRole('menu', { name: 'Conversations' });
        expect(title).toHaveAttribute('aria-expanded', 'true');
        expect(within(menu).getByRole('menuitem', { name: 'New chat' })).toHaveFocus();
        const rows = within(menu).getAllByRole('menuitemradio');
        expect(rows.map((r) => r.getAttribute('aria-checked'))).toEqual(['true', 'false']);
        expect(rows[1]).toHaveTextContent('Footer copy for the launch');
        expect(rows[1]).toHaveTextContent('no changes');
        expect(within(menu).getByText('taw.local')).toBeInTheDocument();
    });

    it('moves with the arrow keys and closes on Esc, back on the title', () => {
        const { connect } = setup();
        connect([], { conversations });
        const title = screen.getByRole('button', { name: /^Conversation:/ });
        fireEvent.click(title);
        const menu = screen.getByRole('menu');
        fireEvent.keyDown(document.activeElement!, { key: 'ArrowDown' });
        expect(within(menu).getAllByRole('menuitemradio')[0]).toHaveFocus();
        fireEvent.keyDown(document.activeElement!, { key: 'ArrowUp' });
        expect(within(menu).getByRole('menuitem', { name: 'New chat' })).toHaveFocus();
        fireEvent.keyDown(document.activeElement!, { key: 'Escape' });
        expect(screen.queryByRole('menu')).toBeNull();
        expect(title).toHaveFocus();
    });

    it('starts a new chat without asking, and shows the empty first-open panel when the bridge says so', async () => {
        const { connect, send, emit } = setup();
        connect(history, { conversations });
        fireEvent.click(screen.getByRole('button', { name: /^Conversation:/ }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'New chat' }));
        await flush();
        expect(send).toHaveBeenCalledWith('/conversation', { session: null });
        emit({
            type: 'hello',
            protocol: 2,
            session: null,
            working: false,
            busy: null,
            takenOver: false,
            history: [],
            conversations: [{ ...conversations[0], current: false }],
        });
        expect(screen.getByText('Point at anything on the page')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /^Conversation: New chat\./ })).toBeInTheDocument();
    });

    it('opens an earlier conversation: “Opening…” until its history arrives', async () => {
        const { connect, send, emit } = setup();
        connect([], { conversations });
        fireEvent.click(screen.getByRole('button', { name: /^Conversation:/ }));
        fireEvent.click(screen.getByRole('menuitemradio', { name: /Footer copy/ }));
        expect(screen.getByText('Opening “Footer copy for the launch”…')).toBeInTheDocument();
        expect(screen.getByLabelText('Message')).toBeDisabled();
        await flush();
        expect(send).toHaveBeenCalledWith('/conversation', { session: 's0' });
        emit({
            type: 'hello',
            protocol: 2,
            session: 's0',
            working: false,
            busy: null,
            takenOver: false,
            history: [
                { type: 'turn', turn: 1, text: 'Footer copy for the launch', chips: [] },
                { type: 'done', turn: 1, outcome: 'ok', message: '', changed: [] },
                { type: 'resumed', at: new Date().toISOString() },
            ],
            conversations,
        });
        expect(screen.queryByText(/^Opening/)).toBeNull();
        expect(screen.getByText('Footer copy for the launch', { selector: '.bubble' })).toBeInTheDocument();
        expect(screen.getByText(/^Resumed today, /)).toBeInTheDocument();
    });

    it('turns New chat and the other conversations off while Claude works, and says why', () => {
        const { connect } = setup();
        connect([{ type: 'turn', turn: 1, text: 'slow', chips: [] }], { conversations, working: true });
        fireEvent.click(screen.getByRole('button', { name: /^Conversation:/ }));
        expect(screen.getByRole('menuitem', { name: 'New chat' })).toHaveAttribute('aria-disabled', 'true');
        expect(screen.getByRole('menuitemradio', { name: /Footer copy/ })).toHaveAttribute('aria-disabled', 'true');
        expect(screen.getByText('Stop Claude first, or wait for it to finish.')).toBeInTheDocument();
        expect(screen.getAllByRole('menuitemradio')[0]).toHaveTextContent('working…');
    });

    it('replays a reopened conversation read-only: dividers, outcomes, saves without Undo, chips from elsewhere dashed', () => {
        const { connect } = setup();
        connect(history, { conversations });
        expect(screen.getByText(/^Earlier · /)).toBeInTheDocument();
        expect(screen.getByText(/^Resumed /)).toBeInTheDocument();
        expect(document.querySelector('.conversation')!.textContent).toContain('Applied: grid.css');
        expect(screen.queryByRole('button', { name: 'Apply' })).toBeNull();
        const saved = screen.getByText('hero_intro').closest('.saved')!;
        expect(within(saved as HTMLElement).queryByRole('button', { name: 'Undo' })).toBeNull();
        expect(screen.getByText('Work grid · block')).toHaveClass('chip-away');
        expect(screen.getByText('Work grid · block')).toHaveAttribute('title', 'Not on this page');
        expect(screen.getByText('hero_title · field')).not.toHaveClass('chip-away');
    });

    it('sends the page’s path with every message', async () => {
        const { connect, send, type, enter } = setup();
        connect();
        type('Make it bigger');
        enter();
        await flush();
        expect(send).toHaveBeenCalledWith(
            '/message',
            expect.objectContaining({ text: 'Make it bigger', page: '/lens-test/' }),
        );
    });

    it('has no title or menu while not connected: the status takes its place', () => {
        const { status } = setup();
        status('off');
        expect(screen.getByText('Not connected')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Conversation:/ })).toBeNull();
    });
});

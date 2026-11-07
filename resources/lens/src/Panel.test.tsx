import { act, fireEvent, render, screen } from '@testing-library/preact';
import { describe, expect, it, vi } from 'vitest';
import { readManifest } from './manifest';
import type { PickHandlers } from './page';
import { Panel, type PageEnv, type PanelEnv } from './Panel';
import { DEFAULTS, type Prefs } from './prefs';
import { PageMap } from './sourcemap';
import { $, mountFixture } from './testing';

function fakeEnv(viewport = { width: 1440, height: 900, top: 0 }) {
    const env = {
        viewport: () => viewport,
        push: vi.fn<PanelEnv['push']>(),
        theme: vi.fn<PanelEnv['theme']>(),
        save: vi.fn<PanelEnv['save']>(),
        onResize: vi.fn<PanelEnv['onResize']>(() => () => {}),
    };

    return env;
}

const lastSaved = (env: ReturnType<typeof fakeEnv>): Prefs => env.save.mock.calls.at(-1)![0];

function setup(initial: Partial<Prefs> = {}, env = fakeEnv()) {
    render(<Panel host="taw.local" initial={{ ...DEFAULTS, ...initial }} env={env} />);

    return env;
}

describe('docked (default)', () => {
    it('is a labelled aside on the right that pushes the page', () => {
        const env = setup();
        const panel = screen.getByRole('complementary', { name: 'TAW Lens' });
        expect(panel).toHaveAttribute('data-mode', 'docked');
        expect(panel).toHaveAttribute('data-side', 'right');
        expect(env.push).toHaveBeenLastCalledWith('right');
        expect(screen.getByText('taw.local')).toBeInTheDocument();
    });

    it('shows the first-open state and the gestures', () => {
        setup();
        expect(screen.getByText('Point at anything on the page')).toBeInTheDocument();
        expect(screen.getByText('stop picking')).toBeInTheDocument();
    });

    it('keeps the composer off until the bridge connects (build step 4)', () => {
        setup();
        expect(screen.getByLabelText('Message')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Send' })).toBeDisabled();
    });

    it('marks picking and the tree as unavailable without a page under it', () => {
        setup();
        expect(screen.getByRole('button', { name: 'Pick an element' })).toHaveAttribute('aria-disabled', 'true');
        expect(screen.getByRole('button', { name: 'Structure tree' })).toHaveAttribute('aria-pressed', 'false');
    });
});

describe('sheet', () => {
    it('undocks into a sheet: the page gets its width back', () => {
        const env = setup();
        fireEvent.click(screen.getByRole('button', { name: 'Undock into a sheet' }));
        expect(screen.getByRole('complementary')).toHaveAttribute('data-mode', 'sheet');
        expect(env.push).toHaveBeenLastCalledWith(null);
        expect(lastSaved(env)).toMatchObject({ mode: 'sheet', shown: 'sheet', sheet: { x: 1016, y: 72 } });
        expect(screen.getByRole('status')).toHaveTextContent('Lens undocked into a sheet');
    });

    it('docks back with the button (the keyboard path), on the nearer side', () => {
        const env = setup({ mode: 'sheet', shown: 'sheet', sheet: { x: 200, y: 100 } });
        fireEvent.click(screen.getByRole('button', { name: 'Dock as Inspector' }));
        expect(screen.getByRole('complementary')).toHaveAttribute('data-side', 'left');
        expect(env.push).toHaveBeenLastCalledWith('left');
    });

    it('drags by its header and shows the dock zone near an edge; dropping docks there', () => {
        const env = setup({ mode: 'sheet', shown: 'sheet', sheet: { x: 500, y: 100 } });
        const header = screen.getByText('Lens').closest('.header')!;

        fireEvent.pointerDown(header, { button: 0, pointerId: 1, clientX: 600, clientY: 120 });
        fireEvent.pointerMove(header, { pointerId: 1, clientX: 400, clientY: 130 });
        expect(screen.queryByText('Release to dock left')).toBeNull();
        expect(lastSaved(env).sheet).toEqual({ x: 300, y: 110 });

        fireEvent.pointerMove(header, { pointerId: 1, clientX: 190, clientY: 130 });
        expect(screen.getByText('Release to dock left')).toBeInTheDocument();

        fireEvent.pointerUp(header, { pointerId: 1, clientX: 190, clientY: 130 });
        expect(screen.queryByText('Release to dock left')).toBeNull();
        expect(screen.getByRole('complementary')).toHaveAttribute('data-mode', 'docked');
        expect(env.push).toHaveBeenLastCalledWith('left');
    });

    it('stays a sheet when dropped away from the edges', () => {
        setup({ mode: 'sheet', shown: 'sheet', sheet: { x: 500, y: 100 } });
        const header = screen.getByText('Lens').closest('.header')!;
        fireEvent.pointerDown(header, { button: 0, pointerId: 1, clientX: 600, clientY: 120 });
        fireEvent.pointerMove(header, { pointerId: 1, clientX: 650, clientY: 200 });
        fireEvent.pointerUp(header, { pointerId: 1 });
        const panel = screen.getByRole('complementary');
        expect(panel).toHaveAttribute('data-mode', 'sheet');
        expect(panel.style.left).toBe('550px');
        expect(panel.style.top).toBe('180px');
    });

    it('cancels a drag with Escape', () => {
        setup({ mode: 'sheet', shown: 'sheet', sheet: { x: 500, y: 100 } });
        const header = screen.getByText('Lens').closest('.header')!;
        fireEvent.pointerDown(header, { button: 0, pointerId: 1, clientX: 600, clientY: 120 });
        fireEvent.pointerMove(header, { pointerId: 1, clientX: 100, clientY: 120 });
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(screen.queryByText('Release to dock left')).toBeNull();
        expect(screen.getByRole('complementary').style.left).toBe('500px');
    });

    it("doesn't start a drag from a header button", () => {
        const env = setup({ mode: 'sheet', shown: 'sheet', sheet: { x: 500, y: 100 } });
        const button = screen.getByRole('button', { name: 'Hide Lens' });
        const saves = env.save.mock.calls.length;
        fireEvent.pointerDown(button, { button: 0, pointerId: 1, clientX: 600, clientY: 120 });
        fireEvent.pointerMove(button, { pointerId: 1, clientX: 100, clientY: 120 });
        expect(env.save.mock.calls.length).toBe(saves);
    });

    it('starts below the admin bar', () => {
        setup(
            { mode: 'sheet', shown: 'sheet', sheet: { x: 500, y: 0 } },
            fakeEnv({ width: 1440, height: 900, top: 32 }),
        );
        expect(screen.getByRole('complementary').style.top).toBe('44px');
    });
});

describe('hidden', () => {
    it('hides to a tab on the docked edge, gives the page back, and returns as it was', () => {
        const env = setup({ mode: 'sheet', shown: 'sheet', sheet: { x: 300, y: 100 }, side: 'left' });
        fireEvent.click(screen.getByRole('button', { name: 'Hide Lens' }));
        expect(screen.queryByRole('complementary')).toBeNull();
        const reopen = screen.getByRole('button', { name: 'Show Lens' });
        expect(reopen).toHaveAttribute('data-side', 'left');
        expect(reopen).toHaveFocus();
        expect(env.push).toHaveBeenLastCalledWith(null);

        fireEvent.click(reopen);
        expect(screen.getByRole('complementary')).toHaveAttribute('data-mode', 'sheet');
        expect(screen.getByRole('button', { name: 'Hide Lens' })).toHaveFocus();
    });
});

describe('theme', () => {
    it('follows the system until switched, and remembers the choice', () => {
        const env = setup();
        expect(env.theme).toHaveBeenLastCalledWith('system');
        fireEvent.click(screen.getByRole('button', { name: /follows the system/ }));
        expect(env.theme).toHaveBeenLastCalledWith('light');
        fireEvent.click(screen.getByRole('button', { name: /Theme: light/ }));
        expect(env.theme).toHaveBeenLastCalledWith('dark');
        expect(lastSaved(env).theme).toBe('dark');
    });
});

it('re-reads the viewport on resize', () => {
    let size = { width: 1440, height: 900, top: 0 };
    let resize = () => {};
    const env = { ...fakeEnv(), viewport: () => size, onResize: vi.fn((cb: () => void) => ((resize = cb), () => {})) };
    setup({ mode: 'sheet', shown: 'sheet', sheet: { x: 1000, y: 100 } }, env);
    size = { width: 800, height: 600, top: 0 };
    act(() => resize());
    expect(screen.getByRole('complementary').style.height).toBe('576px');
});

describe('picking and inspecting (build step 3)', () => {
    function withPage(initial: Partial<Prefs> = {}) {
        mountFixture();
        let handlers: PickHandlers | null = null;
        const stop = vi.fn();
        const page: PageEnv = {
            map: vi.fn(() => new PageMap(document, readManifest(document)!)),
            pick: vi.fn((h: PickHandlers) => {
                handlers = h;
                return stop;
            }),
            sampler: null,
            onAsset: () => () => {},
            reveal: vi.fn(),
            editor: 'vscode://file/{path}:{line}',
        };
        const env = { ...fakeEnv(), page };
        setup(initial, env);
        const picker = () => {
            if (!handlers) {
                throw new Error('not picking');
            }
            return handlers;
        };
        return { page, stop, picker };
    }

    const chips = () => [...document.querySelectorAll('.chip')].map((chip) => chip.textContent);

    it('picks on the page until Esc: a click shows the inspect card, shift-click adds', () => {
        const { page, stop, picker } = withPage();
        const pick = screen.getByRole('button', { name: 'Pick an element' });
        fireEvent.click(pick);
        expect(pick).toHaveAttribute('aria-pressed', 'true');
        expect(page.pick).toHaveBeenCalledTimes(1);

        act(() => picker().hover($('h1')));
        act(() => picker().pick($('h1'), false));
        expect(screen.getByRole('region', { name: 'Inspect' })).toHaveTextContent('hero_heading');
        expect(chips()).toEqual([]);

        act(() => picker().pick($('h2'), true));
        expect(chips()).toEqual(['hero_heading', '“Why TAW Lens”']);
        expect(screen.getByRole('region', { name: 'Inspect' })).toHaveTextContent('Template text');

        act(() => picker().pick($('h2'), true));
        expect(chips()).toEqual([]);

        act(() => picker().stop());
        expect(pick).toHaveAttribute('aria-pressed', 'false');
        expect(stop).toHaveBeenCalled();
    });

    it('stops picking when Lens hides', () => {
        const { stop } = withPage();
        fireEvent.click(screen.getByRole('button', { name: 'Pick an element' }));
        fireEvent.click(screen.getByRole('button', { name: 'Hide Lens' }));
        expect(stop).toHaveBeenCalled();
    });

    it('clears the selection from the card', () => {
        const { picker } = withPage();
        fireEvent.click(screen.getByRole('button', { name: 'Pick an element' }));
        act(() => picker().pick($('h1'), false));
        fireEvent.click(screen.getByRole('button', { name: 'Clear selection' }));
        expect(screen.queryByRole('region', { name: 'Inspect' })).toBeNull();
        expect(screen.getByText('Point at anything on the page')).toBeInTheDocument();
    });

    it('browses the structure tree: select a row, back to chat for its card, "Show in tree" finds it again', () => {
        const { page } = withPage();
        fireEvent.click(screen.getByRole('button', { name: 'Structure tree' }));
        expect(screen.getByRole('button', { name: 'Structure tree' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('tree', { name: 'Structure' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('treeitem', { name: /^Hero/ }));
        expect(chips()).toEqual(['Hero']);
        expect(screen.getByText('Shift-click to add more')).toBeInTheDocument();
        expect(page.reveal).toHaveBeenCalledWith($('section.hero'));
        expect(screen.getByRole('treeitem', { name: /^Hero/ })).toHaveAttribute('aria-selected', 'true');

        fireEvent.click(screen.getByRole('button', { name: 'Back to chat' }));
        expect(screen.getByRole('region', { name: 'Inspect' })).toHaveTextContent('Block · hero');

        fireEvent.click(screen.getByRole('button', { name: 'Show in tree' }));
        expect(screen.getByRole('treeitem', { name: /^Hero/ })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByRole('treeitem', { name: /^hero_heading/ })).toBeInTheDocument();
    });

    it('opens the rows above a selection made on the page', () => {
        const { picker } = withPage();
        fireEvent.click(screen.getByRole('button', { name: 'Pick an element' }));
        act(() => picker().pick($('.faq h3'), false));
        fireEvent.click(screen.getByRole('button', { name: 'Structure tree' }));
        expect(screen.getByRole('treeitem', { name: /^faq_items/ })).toHaveAttribute('aria-selected', 'true');
    });

    it('says so on a page without a source map', () => {
        const { page } = withPage();
        vi.mocked(page.map).mockReturnValue(null);
        fireEvent.click(screen.getByRole('button', { name: 'Structure tree' }));
        expect(screen.getByText('No source map on this page')).toBeInTheDocument();
    });
});

/**
 * The Lens panel (component 01, screens 01–04; the states of component 04, screen 07): the shell, picking, the
 * inspect card, the structure tree, and the chat with Claude through the bridge `taw lens` runs.
 *
 * Docked (default): full height on the right or left edge, pushing the page. Sheet: a 400×600 floating
 * panel dragged by its header; dropped within reach of an edge, it docks there. Hidden: a small tab on
 * the docked edge brings it back. The Dock/Undock button is the keyboard equivalent of dragging.
 */
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'preact/hooks';
import type { BridgeClient, BridgeHandlers, Connection } from './bridge';
import { Connecting, Conversation, NotConnected } from './Chat';
import { dropped, emptyChat, markApproval, reduce, type BridgeEvent, type Chat, type Turn } from './chatModel';
import { chipLabel, shortName, type Target } from './describe';
import { __ } from './i18n';
import { InspectCard } from './InspectCard';
import {
    DockIcon,
    GripIcon,
    HideDockedIcon,
    HideSheetIcon,
    LensMark,
    PickIcon,
    SendIcon,
    SpinnerIcon,
    StopIcon,
    ThemeDarkIcon,
    ThemeLightIcon,
    ThemeSystemIcon,
    TreeIcon,
    UndockIcon,
} from './icons';
import {
    clampSheet,
    defaultSheet,
    dockZone,
    nearestSide,
    pageArea,
    sheetHeight,
    type Point,
    type Viewport,
} from './layout';
import { Overlay, type RingSpec } from './Overlay';
import type { PickHandlers } from './page';
import { nextTheme, type Prefs, type Side, type ThemeChoice } from './prefs';
import type { Sampler } from './ring';
import { messageChips, selectionsPayload } from './payload';
import type { PageMap } from './sourcemap';
import { Tree } from './Tree';
import { buildTree, rowIdFor } from './treeModel';

/** What the panel needs from the page around it (the real one in index.tsx, a fake in tests). */
export interface PanelEnv {
    viewport(): Viewport;
    /** Make room for the docked panel on `side`, or give the page its full width back (null). */
    push(side: Side | null): void;
    theme(choice: ThemeChoice): void;
    save(prefs: Prefs): void;
    /** Calls back when the viewport changes size; returns the unsubscribe. */
    onResize(callback: () => void): () => void;
    /** The page under the panel: its source map, the picker, the contrast engine. Absent in shell-only tests. */
    page?: PageEnv;
    /** Connects to the bridge `taw lens` runs (bridge.ts). Absent: the chat stays "not connected". */
    bridge?: (handlers: BridgeHandlers) => BridgeClient;
}

export interface PageEnv {
    /** The page's source map; `fresh` scans the page again (it may have changed since). Null without one. */
    map(fresh?: boolean): PageMap | null;
    /** Starts picking; returns the function that stops it. */
    pick(handlers: PickHandlers): () => void;
    sampler: Sampler | null;
    /** Calls back when an image the contrast engine needed has loaded; returns the unsubscribe. */
    onAsset(callback: () => void): () => void;
    /** Scrolls an element into view when it's off screen. */
    reveal(el: Element): void;
    /** "Open in editor" link pattern, with {path} and {line}. */
    editor: string;
    /** The page's address, for what Claude receives about the selections. */
    url: string;
}

/** A stable key per page element, for selections made by clicking. */
const elementKeys = new WeakMap<Element, string>();
let nextElementKey = 0;
const elementKey = (el: Element): string => {
    let key = elementKeys.get(el);
    if (!key) {
        key = `e:${++nextElementKey}`;
        elementKeys.set(el, key);
    }
    return key;
};

interface Drag {
    pointer: number;
    start: Point;
    from: Point;
}

const themeLabel = (theme: ThemeChoice): string =>
    theme === 'light'
        ? __('Theme: light. Switch to dark', 'taw-core')
        : theme === 'dark'
          ? __('Theme: dark. Follow the system', 'taw-core')
          : __('Theme: follows the system. Switch to light', 'taw-core');

const ThemeIcon = ({ theme }: { theme: ThemeChoice }) =>
    theme === 'light' ? <ThemeLightIcon /> : theme === 'dark' ? <ThemeDarkIcon /> : <ThemeSystemIcon />;

export function Panel({ host, initial, env }: { host: string; initial: Prefs; env: PanelEnv }) {
    const [prefs, setPrefs] = useState<Prefs>(initial);
    const [viewport, setViewport] = useState<Viewport>(() => env.viewport());
    const [drag, setDrag] = useState<Drag | null>(null);
    const [zone, setZone] = useState<Side | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const reopenRef = useRef<HTMLButtonElement>(null);
    const hideRef = useRef<HTMLButtonElement>(null);
    const focusAfter = useRef<'reopen' | 'hide' | null>(null);

    const update = useCallback((change: Partial<Prefs>) => setPrefs((current) => ({ ...current, ...change })), []);

    // Build step 3: picking, the selection, the structure view.
    const page = env.page;
    // undefined: the page isn't scanned yet; null: it has no source map.
    const [scanned, setMap] = useState<PageMap | null | undefined>(undefined);
    const map = scanned ?? null;
    const [picking, setPicking] = useState(false);
    const [view, setView] = useState<'chat' | 'tree'>('chat');
    const [selection, setSelection] = useState<Target[]>([]);
    const [primary, setPrimary] = useState<string | null>(null);
    const [hover, setHover] = useState<{ target: Target; label: boolean } | null>(null);
    const [reveal, setReveal] = useState<string | null>(null);
    const [assets, setAssets] = useState(0);
    // The inspect card shows for a new selection; once it's sent with a message, the chips stand for it.
    const [cardOpen, setCardOpen] = useState(true);

    // Build step 4: the chat, through the bridge.
    const [connection, setConnection] = useState<Connection>(env.bridge ? 'connecting' : 'off');
    const [chat, setChat] = useState<Chat>(emptyChat);
    const [draft, setDraft] = useState('');
    const client = useRef<BridgeClient | null>(null);
    const lastConnection = useRef<Connection>(connection);
    const lastSent = useRef<{ text: string; context: string; chips: string[] } | null>(null);
    const bodyRef = useRef<HTMLDivElement>(null);
    const stick = useRef(true);
    const inputRef = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        if (!env.bridge) {
            return;
        }
        const announceEvent = (event: BridgeEvent) => {
            if (event.type === 'approval') {
                setAnnouncement(__('Claude needs your OK for a change', 'taw-core'));
            } else if (event.type === 'done') {
                setAnnouncement(
                    event.outcome === 'ok'
                        ? __('Claude finished', 'taw-core')
                        : event.outcome === 'stopped'
                          ? __('Stopped', 'taw-core')
                          : __('Claude stopped before finishing', 'taw-core'),
                );
            }
        };
        const bridge = env.bridge({
            status(next) {
                if (lastConnection.current === 'connected' && next !== 'connected') {
                    setChat(dropped);
                }
                lastConnection.current = next;
                setConnection(next);
            },
            event(event) {
                setChat((current) => reduce(current, event));
                announceEvent(event);
            },
        });
        client.current = bridge;

        return () => {
            bridge.close();
            client.current = null;
        };
    }, [env]);

    // Follow the conversation as it grows, unless the developer scrolled up to read.
    useLayoutEffect(() => {
        const body = bodyRef.current;
        if (body && stick.current) {
            body.scrollTop = body.scrollHeight;
        }
    }, [chat]);
    const onBodyScroll = () => {
        const body = bodyRef.current;
        if (body) {
            stick.current = body.scrollHeight - body.scrollTop - body.clientHeight < 48;
        }
    };

    const freshMap = useCallback(() => {
        const next = page?.map(true) ?? null;
        setMap(next);
        return next;
    }, [page]);
    useEffect(() => page?.onAsset(() => setAssets((n) => n + 1)), [page]);

    const targetFor = useCallback(
        (current: PageMap | null, el: Element): Target => ({
            key: elementKey(el),
            attribution: current ? current.attribute(el) : { kind: 'element', node: 0, text: '' },
            elements: [el],
            at: current ? current.nodeOf(el) : 0,
        }),
        [],
    );

    const select = useCallback(
        (target: Target, add: boolean) => {
            setSelection((current) => {
                if (!add) {
                    return [target];
                }
                return current.some((t) => t.key === target.key)
                    ? current.filter((t) => t.key !== target.key)
                    : [...current, target];
            });
            setPrimary(target.key);
            setCardOpen(true);
            setAnnouncement(`${__('Selected', 'taw-core')} ${map ? shortName(map.manifest, target) : ''}`.trim());
        },
        [map],
    );
    const clearSelection = () => {
        setSelection([]);
        setPrimary(null);
        setAnnouncement(__('Selection cleared', 'taw-core'));
    };

    // Picking: the page reports hovers and clicks until Esc, the Pick button, or Lens hides.
    useEffect(() => {
        if (!picking || !page || prefs.mode === 'hidden') {
            return;
        }
        if (scanned === undefined) {
            freshMap();
            return;
        }
        const current = map;
        const stop = page.pick({
            hover: (el) => setHover(el ? { target: targetFor(current, el), label: true } : null),
            pick: (el, add) => {
                const target = targetFor(current, el);
                select(target, add);
                const row = rowIdFor(target);
                if (row) {
                    setReveal(row);
                }
            },
            stop: () => setPicking(false),
        });

        return () => {
            stop();
            setHover(null);
        };
    }, [picking, page, prefs.mode, scanned, map, freshMap, targetFor, select]);

    const tree = useMemo(() => (map ? buildTree(map) : null), [map]);
    const selectedRows = useMemo(
        () => new Set(selection.map(rowIdFor).filter((id): id is string => id !== null)),
        [selection],
    );
    const openTree = () => {
        if (view === 'tree') {
            setView('chat');
            return;
        }
        freshMap();
        setView('tree');
    };
    const showInTree = (target: Target) => {
        if (!map) {
            freshMap();
        }
        setView('tree');
        setReveal(rowIdFor(target));
    };
    const selectFromTree = (target: Target, add: boolean) => {
        select(target, add);
        if (target.elements[0]) {
            page?.reveal(target.elements[0]);
        }
    };

    const active = selection.find((t) => t.key === primary) ?? selection[selection.length - 1] ?? null;

    const connected = connection === 'connected';
    const send = async (message: { text: string; context: string; chips: string[] }) => {
        const bridge = client.current;
        if (!bridge || !connected || chat.working || message.text.trim() === '') {
            return;
        }
        stick.current = true;
        const result = await bridge.send('/message', message);
        if (result.ok) {
            lastSent.current = message;
            return true;
        }
        setAnnouncement(
            result.status === 409
                ? __('Claude is still working on the last message', 'taw-core')
                : __('Lens couldn’t send that. Is taw lens still running?', 'taw-core'),
        );

        return false;
    };
    const submit = async () => {
        const text = draft.trim();
        if (text === '') {
            return;
        }
        const message = {
            text,
            context: selectionsPayload(map?.manifest ?? null, selection, {
                url: page?.url ?? '',
                title: map?.manifest.nodes.get(0)?.label ?? '',
            }),
            chips: messageChips(map?.manifest ?? null, selection),
        };
        setDraft('');
        if (await send(message)) {
            setCardOpen(false);
        } else {
            setDraft(text);
        }
    };
    const retry = (turn: Turn) => {
        const sent = lastSent.current;
        void send(sent && sent.text === turn.text ? sent : { text: turn.text, context: '', chips: turn.chips });
    };
    const answer = async (id: string, allow: boolean) => {
        setChat((current) => markApproval(current, id, 'sending'));
        const result = await client.current?.send('/approve', { id, allow });
        if (!result?.ok) {
            setChat((current) => markApproval(current, id, 'pending'));
            setAnnouncement(__('Lens couldn’t send your answer. Try again.', 'taw-core'));
        }
    };
    const stop = () => void client.current?.send('/stop', {});
    const takeOver = () => void client.current?.send('/take-over', {});
    const onComposerKey = (event: KeyboardEvent) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            void submit();
        }
    };
    // The composer grows with what's typed, up to six lines.
    useLayoutEffect(() => {
        const input = inputRef.current;
        if (input) {
            input.style.height = 'auto';
            input.style.height = `${Math.min(input.scrollHeight, 132)}px`;
        }
    }, [draft]);
    const rings: RingSpec[] =
        prefs.mode === 'hidden'
            ? []
            : [
                  ...selection.flatMap((target) =>
                      target.elements.map((el, i) => ({
                          key: `${target.key}:${i}`,
                          el,
                          state: 'selected' as const,
                          label: i === 0 && map ? chipLabel(map.manifest, target) : undefined,
                      })),
                  ),
                  ...(hover
                      ? hover.target.elements
                            .filter((el) => !selection.some((t) => t.elements.includes(el)))
                            .map((el, i) => ({
                                key: `hover:${i}`,
                                el,
                                state: 'hover' as const,
                                label:
                                    hover.label && i === 0 && map ? chipLabel(map.manifest, hover.target) : undefined,
                            }))
                      : []),
              ];

    useEffect(() => env.push(prefs.mode === 'docked' ? prefs.side : null), [env, prefs.mode, prefs.side]);
    useEffect(() => env.theme(prefs.theme), [env, prefs.theme]);
    useEffect(() => env.save(prefs), [env, prefs]);
    useEffect(() => env.onResize(() => setViewport(env.viewport())), [env]);
    useEffect(() => {
        if (focusAfter.current === 'reopen') {
            reopenRef.current?.focus();
        } else if (focusAfter.current === 'hide') {
            hideRef.current?.focus();
        }
        focusAfter.current = null;
    }, [prefs.mode]);

    const sheet = clampSheet(prefs.sheet ?? defaultSheet(viewport), viewport);

    const dock = (side: Side) => {
        update({ mode: 'docked', shown: 'docked', side });
        setAnnouncement(
            side === 'left' ? __('Lens docked on the left', 'taw-core') : __('Lens docked on the right', 'taw-core'),
        );
    };
    const undock = () => {
        update({ mode: 'sheet', shown: 'sheet', sheet });
        setAnnouncement(__('Lens undocked into a sheet', 'taw-core'));
    };
    const hide = () => {
        focusAfter.current = 'reopen';
        setPicking(false);
        update({ mode: 'hidden' });
    };
    const show = () => {
        focusAfter.current = 'hide';
        update({ mode: prefs.shown });
    };

    // Drag the sheet by its header; dropping it within reach of an edge docks it there. Escape cancels.
    const onPointerDown = (event: PointerEvent) => {
        if (prefs.mode !== 'sheet' || event.button !== 0 || (event.target as Element).closest('button')) {
            return;
        }
        (event.currentTarget as Element).setPointerCapture?.(event.pointerId);
        setDrag({ pointer: event.pointerId, start: { x: event.clientX, y: event.clientY }, from: sheet });
        event.preventDefault();
    };
    const onPointerMove = (event: PointerEvent) => {
        if (!drag || event.pointerId !== drag.pointer) {
            return;
        }
        const next = clampSheet(
            { x: drag.from.x + event.clientX - drag.start.x, y: drag.from.y + event.clientY - drag.start.y },
            viewport,
        );
        update({ sheet: next });
        setZone(dockZone(next.x, viewport));
    };
    const onPointerUp = (event: PointerEvent) => {
        if (!drag || event.pointerId !== drag.pointer) {
            return;
        }
        setDrag(null);
        setZone(null);
        if (zone) {
            dock(zone);
        }
    };
    useEffect(() => {
        if (!drag) {
            return;
        }
        const cancel = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                update({ sheet: drag.from });
                setDrag(null);
                setZone(null);
            }
        };
        document.addEventListener('keydown', cancel, true);

        return () => document.removeEventListener('keydown', cancel, true);
    }, [drag, update]);

    const live = (
        <div class="sr-only" role="status" aria-live="polite">
            {announcement}
        </div>
    );

    if (prefs.mode === 'hidden') {
        return (
            <div class="lens">
                <button
                    ref={reopenRef}
                    type="button"
                    class="reopen"
                    data-side={prefs.side}
                    aria-label={__('Show Lens', 'taw-core')}
                    title={__('Show Lens', 'taw-core')}
                    onClick={show}
                >
                    <LensMark />
                </button>
                {live}
            </div>
        );
    }

    const docked = prefs.mode === 'docked';
    const style = docked
        ? { '--lens-top': `${viewport.top}px` }
        : { left: `${sheet.x}px`, top: `${sheet.y}px`, height: `${sheetHeight(viewport)}px` };

    return (
        <div class="lens">
            {zone && (
                <div
                    class="dock-zone"
                    data-side={zone}
                    aria-hidden="true"
                    style={{ '--lens-top': `${viewport.top}px` }}
                >
                    <span class="dock-zone-label">
                        {zone === 'left'
                            ? __('Release to dock left', 'taw-core')
                            : __('Release to dock right', 'taw-core')}
                    </span>
                </div>
            )}
            <aside
                class="panel"
                aria-label={__('TAW Lens', 'taw-core')}
                data-mode={prefs.mode}
                data-side={prefs.side}
                data-dragging={drag ? '' : undefined}
                style={style}
            >
                <div
                    class="header"
                    onPointerDown={onPointerDown}
                    onPointerMove={onPointerMove}
                    onPointerUp={onPointerUp}
                    onPointerCancel={onPointerUp}
                >
                    <div class="brand">
                        {docked ? (
                            <LensMark />
                        ) : (
                            <span class="grip">
                                <GripIcon />
                            </span>
                        )}
                        <span class="brand-name">{__('Lens', 'taw-core')}</span>
                        {docked && host && connected && <span class="brand-host">{host}</span>}
                        {connection === 'connecting' ? (
                            <span class="status">
                                <SpinnerIcon size={12} />
                                {__('Connecting…', 'taw-core')}
                            </span>
                        ) : connection !== 'connected' ? (
                            <span class="status">
                                <span class="status-dot" aria-hidden="true" />
                                {connection === 'outdated'
                                    ? __('Restart needed', 'taw-core')
                                    : __('Not connected', 'taw-core')}
                            </span>
                        ) : null}
                    </div>
                    <div class="tools">
                        <button
                            type="button"
                            class="icon-button"
                            aria-label={__('Pick an element', 'taw-core')}
                            title={picking ? __('Stop picking (Esc)', 'taw-core') : __('Pick an element', 'taw-core')}
                            aria-pressed={picking}
                            aria-disabled={page ? undefined : 'true'}
                            onClick={() => page && setPicking(!picking)}
                        >
                            <PickIcon />
                        </button>
                        <button
                            type="button"
                            class="icon-button"
                            aria-label={__('Structure tree', 'taw-core')}
                            title={__('Structure tree', 'taw-core')}
                            aria-pressed={view === 'tree'}
                            aria-disabled={page ? undefined : 'true'}
                            onClick={() => page && openTree()}
                        >
                            <TreeIcon />
                        </button>
                        <button
                            type="button"
                            class="icon-button"
                            aria-label={themeLabel(prefs.theme)}
                            title={themeLabel(prefs.theme)}
                            onClick={() => update({ theme: nextTheme(prefs.theme) })}
                        >
                            <ThemeIcon theme={prefs.theme} />
                        </button>
                        {docked ? (
                            <button
                                type="button"
                                class="icon-button"
                                aria-label={__('Undock into a sheet', 'taw-core')}
                                title={__('Undock into a sheet', 'taw-core')}
                                onClick={undock}
                            >
                                <UndockIcon />
                            </button>
                        ) : (
                            <button
                                type="button"
                                class="icon-button"
                                aria-label={__('Dock as Inspector', 'taw-core')}
                                title={__('Dock as Inspector', 'taw-core')}
                                onClick={() => dock(nearestSide(sheet, viewport))}
                            >
                                <DockIcon />
                            </button>
                        )}
                        <button
                            ref={hideRef}
                            type="button"
                            class="icon-button"
                            aria-label={__('Hide Lens', 'taw-core')}
                            title={__('Hide Lens', 'taw-core')}
                            onClick={hide}
                        >
                            {docked ? <HideDockedIcon side={prefs.side} /> : <HideSheetIcon />}
                        </button>
                    </div>
                </div>

                <div class="body" ref={bodyRef} onScroll={onBodyScroll}>
                    {view === 'tree' ? (
                        tree ? (
                            <Tree
                                root={tree}
                                selected={selectedRows}
                                reveal={reveal}
                                onHover={(target) => setHover(target ? { target, label: false } : null)}
                                onSelect={selectFromTree}
                                onBack={() => setView('chat')}
                            />
                        ) : (
                            <div class="empty">
                                <div class="empty-title">{__('No source map on this page', 'taw-core')}</div>
                                <div class="empty-text">
                                    {__(
                                        'Lens maps what TAW renders. Reload the page; if it stays empty, check that the theme calls wp_footer().',
                                        'taw-core',
                                    )}
                                </div>
                            </div>
                        )
                    ) : chat.turns.length > 0 || (chat.busy && connected) ? (
                        <Conversation
                            chat={chat}
                            connection={connection}
                            onAnswer={(id: string, allow: boolean) => void answer(id, allow)}
                            onRetry={retry}
                            onTakeOver={takeOver}
                        />
                    ) : connection === 'connecting' ? (
                        <Connecting />
                    ) : connection !== 'connected' ? (
                        <NotConnected outdated={connection === 'outdated'} />
                    ) : active ? (
                        <div class="empty">
                            <div class="empty-text">
                                {__(
                                    'No conversation yet. Select something on the page, or tell Claude what to change.',
                                    'taw-core',
                                )}
                            </div>
                        </div>
                    ) : (
                        <div class="empty">
                            <PickIcon size={22} />
                            <div class="empty-title">{__('Point at anything on the page', 'taw-core')}</div>
                            <div class="empty-text">
                                {__(
                                    'Click an element to see what TAW knows about it, or open the structure tree. Then tell Claude what to change.',
                                    'taw-core',
                                )}
                            </div>
                            <div class="gestures">
                                <span>
                                    <span class="kbd">{__('Click', 'taw-core')}</span> {__('select', 'taw-core')}
                                </span>
                                <span>
                                    <span class="kbd">{__('⇧ Click', 'taw-core')}</span>{' '}
                                    {__('add to selection', 'taw-core')}
                                </span>
                                <span>
                                    <span class="kbd">{__('Esc', 'taw-core')}</span> {__('stop picking', 'taw-core')}
                                </span>
                            </div>
                        </div>
                    )}
                </div>

                {view === 'chat' && cardOpen && active && map && page && (
                    <InspectCard
                        manifest={map.manifest}
                        target={active}
                        editor={page.editor}
                        onClose={clearSelection}
                        onShowInTree={showInTree}
                    />
                )}

                {selection.length > 0 && (view === 'tree' || selection.length > 1 || !cardOpen) && (
                    <div class="chips" role="list" aria-label={__('Selection', 'taw-core')}>
                        {selection.map((target) => (
                            <span role="listitem" key={target.key}>
                                <button
                                    type="button"
                                    class="chip mono"
                                    data-primary={selection.length > 1 && target.key === active?.key ? '' : undefined}
                                    onMouseEnter={() => setHover({ target, label: false })}
                                    onMouseLeave={() => setHover(null)}
                                    onFocus={() => setHover({ target, label: false })}
                                    onBlur={() => setHover(null)}
                                    onClick={() => {
                                        setPrimary(target.key);
                                        setCardOpen(true);
                                        setView('chat');
                                    }}
                                >
                                    {map ? shortName(map.manifest, target) : target.key}
                                </button>
                            </span>
                        ))}
                        {selection.length === 1 && (
                            <span class="chips-hint">{__('Shift-click to add more', 'taw-core')}</span>
                        )}
                    </div>
                )}

                <div class="composer">
                    <label class="sr-only" for="lens-message">
                        {__('Message', 'taw-core')}
                    </label>
                    <div class="composer-box" data-disabled={connected && !chat.working ? undefined : ''}>
                        <textarea
                            id="lens-message"
                            ref={inputRef}
                            class="composer-input"
                            rows={1}
                            value={draft}
                            disabled={!connected || chat.working}
                            placeholder={
                                connection === 'off' || connection === 'outdated'
                                    ? __('Start Lens to chat', 'taw-core')
                                    : chat.working
                                      ? __('Claude is working…', 'taw-core')
                                      : __('Tell Claude what to change…', 'taw-core')
                            }
                            onInput={(event) => setDraft((event.currentTarget as HTMLTextAreaElement).value)}
                            onKeyDown={onComposerKey}
                        />
                        {chat.working && connected ? (
                            <button type="button" class="stop" onClick={stop}>
                                <StopIcon />
                                {__('Stop', 'taw-core')}
                            </button>
                        ) : (
                            <button
                                type="button"
                                class="send"
                                aria-label={__('Send', 'taw-core')}
                                disabled={!connected || draft.trim() === ''}
                                onClick={() => void submit()}
                            >
                                <SendIcon />
                            </button>
                        )}
                    </div>
                </div>
            </aside>
            <Overlay
                rings={rings}
                sampler={page?.sampler ?? null}
                top={viewport.top}
                area={pageArea(viewport, prefs.mode === 'docked' ? prefs.side : null)}
                refresh={assets}
            />
            {live}
        </div>
    );
}

/**
 * The Lens panel shell (component 01, screens 01–03; first-open state from component 04, screen 07).
 *
 * Docked (default): full height on the right or left edge, pushing the page. Sheet: a 400×600 floating
 * panel dragged by its header; dropped within reach of an edge, it docks there. Hidden: a small tab on
 * the docked edge brings it back. The Dock/Undock button is the keyboard equivalent of dragging.
 */
import { useCallback, useEffect, useRef, useState } from 'preact/hooks';
import { __ } from './i18n';
import {
    DockIcon,
    GripIcon,
    HideDockedIcon,
    HideSheetIcon,
    LensMark,
    PickIcon,
    SendIcon,
    ThemeDarkIcon,
    ThemeLightIcon,
    ThemeSystemIcon,
    TreeIcon,
    UndockIcon,
} from './icons';
import { clampSheet, defaultSheet, dockZone, nearestSide, sheetHeight, type Point, type Viewport } from './layout';
import { nextTheme, type Prefs, type Side, type ThemeChoice } from './prefs';

/** What the panel needs from the page around it (the real one in index.tsx, a fake in tests). */
export interface PanelEnv {
    viewport(): Viewport;
    /** Make room for the docked panel on `side`, or give the page its full width back (null). */
    push(side: Side | null): void;
    theme(choice: ThemeChoice): void;
    save(prefs: Prefs): void;
    /** Calls back when the viewport changes size; returns the unsubscribe. */
    onResize(callback: () => void): () => void;
}

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
                        {docked && host && <span class="brand-host">{host}</span>}
                    </div>
                    <div class="tools">
                        {/* Picking and the structure tree arrive with build step 3. */}
                        <button
                            type="button"
                            class="icon-button"
                            aria-label={__('Pick an element', 'taw-core')}
                            aria-pressed="false"
                            aria-disabled="true"
                        >
                            <PickIcon />
                        </button>
                        <button
                            type="button"
                            class="icon-button"
                            aria-label={__('Structure tree', 'taw-core')}
                            aria-pressed="false"
                            aria-disabled="true"
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

                <div class="body">
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
                </div>

                {/* The chat connects to the bridge in build step 4; until then, the "not connected" composer. */}
                <div class="composer">
                    <label class="sr-only" for="lens-message">
                        {__('Message', 'taw-core')}
                    </label>
                    <div class="composer-box" data-disabled="">
                        <input
                            id="lens-message"
                            class="composer-input"
                            disabled
                            placeholder={__('Start Lens to chat', 'taw-core')}
                        />
                        <button type="button" class="send" aria-label={__('Send', 'taw-core')} disabled>
                            <SendIcon />
                        </button>
                    </div>
                </div>
            </aside>
            {live}
        </div>
    );
}

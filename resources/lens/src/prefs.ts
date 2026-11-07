/**
 * The panel's layout and theme, remembered per browser (design: "theme follows the system, with a manual
 * override remembered per browser"; the dock side and sheet position are remembered the same way).
 *
 * Storage is a convenience: it can be missing, full or blocked (private windows), so every read is
 * validated field by field and every failure falls back to the defaults.
 */

export type Mode = 'docked' | 'sheet' | 'hidden';
export type Side = 'left' | 'right';
export type ThemeChoice = 'system' | 'light' | 'dark';

export interface Prefs {
    /** How the panel shows now. */
    mode: Mode;
    /** How it shows again after Hide (never `hidden`). */
    shown: Exclude<Mode, 'hidden'>;
    /** The edge it docks to. */
    side: Side;
    /** The sheet's top-left corner, in viewport pixels; null until the developer first moves it. */
    sheet: { x: number; y: number } | null;
    theme: ThemeChoice;
}

export const STORAGE_KEY = 'taw-lens:prefs';

export const DEFAULTS: Prefs = { mode: 'docked', shown: 'docked', side: 'right', sheet: null, theme: 'system' };

const oneOf = <T extends string>(value: unknown, options: readonly T[], fallback: T): T =>
    typeof value === 'string' && (options as readonly string[]).includes(value) ? (value as T) : fallback;

const finite = (value: unknown): value is number => typeof value === 'number' && Number.isFinite(value);

/** Whatever was stored, as valid prefs: unknown or broken fields take their default. */
export function parsePrefs(raw: unknown): Prefs {
    if (typeof raw !== 'object' || raw === null) {
        return { ...DEFAULTS };
    }
    const value = raw as Record<string, unknown>;
    const sheet = value.sheet as Record<string, unknown> | null | undefined;

    return {
        mode: oneOf(value.mode, ['docked', 'sheet', 'hidden'], DEFAULTS.mode),
        shown: oneOf(value.shown, ['docked', 'sheet'], DEFAULTS.shown),
        side: oneOf(value.side, ['left', 'right'], DEFAULTS.side),
        sheet:
            typeof sheet === 'object' && sheet !== null && finite(sheet.x) && finite(sheet.y)
                ? { x: sheet.x, y: sheet.y }
                : null,
        theme: oneOf(value.theme, ['system', 'light', 'dark'], DEFAULTS.theme),
    };
}

export function loadPrefs(storage: Pick<Storage, 'getItem'> | null = safeStorage()): Prefs {
    try {
        const stored = storage?.getItem(STORAGE_KEY);

        return parsePrefs(stored ? JSON.parse(stored) : null);
    } catch {
        return { ...DEFAULTS };
    }
}

export function savePrefs(prefs: Prefs, storage: Pick<Storage, 'setItem'> | null = safeStorage()): void {
    try {
        storage?.setItem(STORAGE_KEY, JSON.stringify(prefs));
    } catch {
        // Full or blocked storage: the panel still works, it just won't remember.
    }
}

function safeStorage(): Storage | null {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

/** The theme button's order: follow the system, then force light, then force dark. */
export const nextTheme = (theme: ThemeChoice): ThemeChoice =>
    theme === 'system' ? 'light' : theme === 'light' ? 'dark' : 'system';

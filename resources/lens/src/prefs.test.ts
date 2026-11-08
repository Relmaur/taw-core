import { describe, expect, it } from 'vitest';
import { DEFAULTS, loadPrefs, nextTheme, parsePrefs, savePrefs, STORAGE_KEY } from './prefs';

describe('parsePrefs', () => {
    it('keeps every valid field', () => {
        const prefs = { mode: 'sheet', shown: 'sheet', side: 'left', sheet: { x: 40, y: 90 }, theme: 'dark' };
        expect(parsePrefs(prefs)).toEqual(prefs);
    });

    it('replaces broken fields with their defaults, one by one', () => {
        expect(
            parsePrefs({ mode: 'floating', shown: 'hidden', side: 'top', sheet: { x: 'a', y: 2 }, theme: 'blue' }),
        ).toEqual(DEFAULTS);
        expect(parsePrefs({ side: 'left', sheet: { x: Infinity, y: 0 } })).toEqual({ ...DEFAULTS, side: 'left' });
    });

    it('starts docked on the right, following the system theme', () => {
        expect(parsePrefs(null)).toEqual({
            mode: 'docked',
            shown: 'docked',
            side: 'right',
            sheet: null,
            theme: 'system',
        });
        expect(parsePrefs('docked')).toEqual(DEFAULTS);
    });
});

describe('storage', () => {
    it('round-trips through storage', () => {
        const store = new Map<string, string>();
        const storage = {
            getItem: (k: string) => store.get(k) ?? null,
            setItem: (k: string, v: string) => void store.set(k, v),
        };
        savePrefs({ ...DEFAULTS, theme: 'light' }, storage);
        expect(JSON.parse(store.get(STORAGE_KEY) ?? '')).toMatchObject({ theme: 'light' });
        expect(loadPrefs(storage).theme).toBe('light');
    });

    it('falls back to the defaults when storage is broken or blocked', () => {
        expect(loadPrefs({ getItem: () => '{not json' })).toEqual(DEFAULTS);
        expect(
            loadPrefs({
                getItem: () => {
                    throw new Error('SecurityError');
                },
            }),
        ).toEqual(DEFAULTS);
        expect(loadPrefs(null)).toEqual(DEFAULTS);
        expect(() =>
            savePrefs(DEFAULTS, {
                setItem: () => {
                    throw new Error('QuotaExceededError');
                },
            }),
        ).not.toThrow();
    });
});

it('cycles the theme: system, light, dark, system', () => {
    expect([nextTheme('system'), nextTheme('light'), nextTheme('dark')]).toEqual(['light', 'dark', 'system']);
});

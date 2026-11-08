import { describe, expect, it } from 'vitest';
import { contrast, luminance, parseHex } from './contrast';
import { cssVar, dark, light, tokensCss, type LensTokens } from './tokens';

describe('contrast', () => {
    it('matches the WCAG reference points', () => {
        expect(contrast('#000000', '#FFFFFF')).toBeCloseTo(21, 5);
        expect(contrast('#FFFFFF', '#FFFFFF')).toBe(1);
        expect(contrast('#777777', '#FFFFFF')).toBeCloseTo(4.48, 2);
        expect(luminance(parseHex('#fff'))).toBe(1);
    });

    it('rejects anything but hex', () => {
        expect(() => parseHex('rgb(0,0,0)')).toThrow();
        expect(() => parseHex('#12345')).toThrow();
    });
});

// Every pair the panel puts on screen, with the WCAG 2.2 AA minimum it must meet:
// 4.5 for text, 3 for non-text UI (focus indicator, status dot, control borders that carry meaning).
const PAIRS: [fg: keyof LensTokens, bg: keyof LensTokens, min: number, what: string][] = [
    ['text', 'surface', 4.5, 'body text'],
    ['muted', 'surface', 4.5, 'secondary text'],
    ['mutedSoft', 'surface', 4.5, 'quiet text'],
    ['text', 'bubble', 4.5, 'your message'],
    ['text', 'chip', 4.5, 'chip label'],
    ['text', 'bubble', 4.5, 'the current conversation in the menu'],
    ['muted', 'bubble', 4.5, 'a menu row’s detail on the current row'],
    ['muted', 'chip', 4.5, 'chip secondary label'],
    ['onAccent', 'accent', 4.5, 'primary button label'],
    ['accent', 'surface', 3, 'focus ring and selection on the panel'],
    ['text', 'amberFill', 4.5, 'text in an approval card'],
    ['amberText', 'amberFill', 4.5, 'file label in an approval card'],
    // Not listed: amberDot. The dot is decorative (aria-hidden) and always sits beside a text label
    // ("Edit 1 file", "Theme busy"), so WCAG 1.4.11's 3:1 doesn't apply; light is 2.97:1 by design.
    // Never let it carry meaning alone.
    ['diffRemovedText', 'diffRemovedFill', 4.5, 'removed diff line'],
    ['diffAddedText', 'diffAddedFill', 4.5, 'added diff line'],
];

describe.each([
    ['light', light],
    ['dark', dark],
])('%s tokens', (_, tokens) => {
    it.each(PAIRS)('%s on %s meets %f (%s)', (fg, bg, min) => {
        expect(contrast(tokens[fg], tokens[bg])).toBeGreaterThanOrEqual(min);
    });

    it('are all hex colours (shadows aside)', () => {
        for (const [name, value] of Object.entries(tokens)) {
            if (!name.endsWith('Shadow')) {
                expect(() => parseHex(value)).not.toThrow();
            }
        }
    });
});

describe('the owner rules', () => {
    it('keeps the accent ink, never blue', () => {
        for (const tokens of [light, dark]) {
            const [r, g, b] = parseHex(tokens.accent);
            expect(Math.max(r, g, b) - Math.min(r, g, b)).toBeLessThan(16); // neutral, no hue
        }
    });

    it('gives both themes the same token names', () => {
        expect(Object.keys(dark).sort()).toEqual(Object.keys(light).sort());
    });
});

describe('tokensCss', () => {
    const css = tokensCss();

    it('names variables in kebab case under --lens-', () => {
        expect(cssVar('mutedSoft')).toBe('--lens-muted-soft');
        expect(css).toContain('--lens-diff-removed-fill: #FDEEEF;');
    });

    it('scopes everything to the shadow host', () => {
        for (const rule of css.split('\n')) {
            expect(rule).toMatch(/^(:host|@media \(prefers-color-scheme: dark\) \{ :host)/);
        }
    });

    it('follows the system unless the developer chose a theme', () => {
        expect(css).toContain(':host(:not([data-theme="light"]))');
        expect(css).toContain(`:host([data-theme="dark"]) { ${cssVar('surface')}: ${dark.surface};`);
    });
});

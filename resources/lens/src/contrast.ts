/**
 * WCAG 2.2 relative luminance and contrast ratio for sRGB hex colours.
 *
 * Used by the token tests (every Lens text/UI pair meets AA in both themes) and, from build step 3,
 * by the selection ring's contrast engine, which picks ink or white per element.
 */

export type Rgb = readonly [number, number, number];

/** `#RGB` or `#RRGGBB` (case-insensitive) → [r, g, b] in 0–255. Throws on anything else. */
export function parseHex(hex: string): Rgb {
    const m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(hex.trim());
    if (!m) {
        throw new Error(`Not a hex colour: ${hex}`);
    }
    const h = m[1].length === 3 ? [...m[1]].map((c) => c + c).join('') : m[1];
    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)) as unknown as Rgb;
}

/** WCAG relative luminance (0 = black, 1 = white). */
export function luminance([r, g, b]: Rgb): number {
    const lin = (c: number) => {
        const s = c / 255;
        return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

/** WCAG contrast ratio between two colours, 1–21. Order doesn't matter. */
export function contrast(a: Rgb | string, b: Rgb | string): number {
    const la = luminance(typeof a === 'string' ? parseHex(a) : a);
    const lb = luminance(typeof b === 'string' ? parseHex(b) : b);
    const [hi, lo] = la > lb ? [la, lb] : [lb, la];
    return (hi + 0.05) / (lo + 0.05);
}

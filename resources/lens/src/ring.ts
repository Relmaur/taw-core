/**
 * The selection ring's contrast engine (component 02, screen 05): per element, not per page.
 *
 * 1. Read what's behind the ring: at points around it, the stack of page elements there, top-down: an image
 *    or video (its pixels), a background image or gradient (its pixels, or its colour stops), else the first
 *    painted background colour; translucent layers are blended over what's under them.
 * 2. Average those colours' WCAG luminance; the ring takes whichever of ink or white contrasts more.
 * 3. The halo is always the other one, so the ring never disappears on a borderline reading.
 *
 * Rings are drawn by Lens's overlay; nothing here touches the element's own styles.
 */
import { luminance, type Rgb } from './contrast';

export const INK = '#1A1C21';
export const WHITE = '#FFFFFF';
const INK_RGB: Rgb = [0x1a, 0x1c, 0x21];
const WHITE_RGB: Rgb = [255, 255, 255];

export interface Rgba {
    r: number;
    g: number;
    b: number;
    a: number;
}

export interface RingColors {
    ring: string;
    halo: string;
    /** The luminance read behind the ring (0–1). */
    luminance: number;
}

/** Ink or white, whichever contrasts more with this luminance; the halo is the other. */
export function ringFor(lum: number): RingColors {
    const ratio = (other: number) => {
        const [hi, lo] = lum > other ? [lum, other] : [other, lum];
        return (hi + 0.05) / (lo + 0.05);
    };
    const inkWins = ratio(luminance(INK_RGB)) >= ratio(luminance(WHITE_RGB));

    return inkWins ? { ring: INK, halo: WHITE, luminance: lum } : { ring: WHITE, halo: INK, luminance: lum };
}

/** Blends a translucent colour over an opaque one. */
export const blend = (top: Rgba, bottom: Rgb): Rgb =>
    [
        top.r * top.a + bottom[0] * (1 - top.a),
        top.g * top.a + bottom[1] * (1 - top.a),
        top.b * top.a + bottom[2] * (1 - top.a),
    ] as const;

/** Composites layers listed top-down over white (the canvas behind every page). */
export function composite(layers: Rgba[]): Rgb {
    let out: Rgb = WHITE_RGB;
    for (let i = layers.length - 1; i >= 0; i--) {
        out = blend(layers[i], out);
    }

    return out;
}

/**
 * A CSS colour as RGBA, for the forms browsers serialize computed colours in (`rgb()`, `rgba()`, with commas or
 * spaces, `#hex`, `transparent`). Others (`oklch()`, `color()`…) go through `fallback`, a canvas in the browser.
 */
export function parseColor(value: string, fallback?: (value: string) => Rgba | null): Rgba | null {
    const v = value.trim().toLowerCase();
    if (v === 'transparent' || v === '') {
        return { r: 0, g: 0, b: 0, a: 0 };
    }
    const hex = /^#([0-9a-f]{3,8})$/.exec(v);
    if (hex) {
        const h = hex[1];
        const full = h.length <= 4 ? [...h].map((c) => c + c).join('') : h;
        if (full.length === 6 || full.length === 8) {
            const n = (i: number) => parseInt(full.slice(i, i + 2), 16);
            return { r: n(0), g: n(2), b: n(4), a: full.length === 8 ? n(6) / 255 : 1 };
        }
    }
    const rgb = /^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)$/.exec(v);
    if (rgb) {
        const alpha = rgb[4] === undefined ? 1 : rgb[4].endsWith('%') ? parseFloat(rgb[4]) / 100 : parseFloat(rgb[4]);
        return { r: +rgb[1], g: +rgb[2], b: +rgb[3], a: Math.max(0, Math.min(1, alpha)) };
    }

    return fallback ? fallback(value) : null;
}

/** The colour stops of a gradient (`linear-gradient(rgb(…) 0%, oklch(…) 100%)`), as written. */
export function gradientStops(image: string): string[] {
    return image.match(/(?:rgba?|hsla?|oklch|oklab|lab|lch|color)\([^()]*\)|#[0-9a-f]{3,8}\b/gi) ?? [];
}

/** Averages colours (opaque results only count their own alpha). */
export function average(colors: Rgba[]): Rgba | null {
    if (colors.length === 0) {
        return null;
    }
    const sum = colors.reduce((acc, c) => ({ r: acc.r + c.r, g: acc.g + c.g, b: acc.b + c.b, a: acc.a + c.a }), {
        r: 0,
        g: 0,
        b: 0,
        a: 0,
    });
    const n = colors.length;

    return { r: sum.r / n, g: sum.g / n, b: sum.b / n, a: sum.a / n };
}

export interface Rect {
    left: number;
    top: number;
    width: number;
    height: number;
}

/** Points around the ring (corners and edge midpoints, `gap` outside the element), kept inside the viewport. */
export function ringPoints(rect: Rect, gap: number, viewport: { width: number; height: number }): [number, number][] {
    const left = rect.left - gap;
    const right = rect.left + rect.width + gap;
    const top = rect.top - gap;
    const bottom = rect.top + rect.height + gap;
    const midX = rect.left + rect.width / 2;
    const midY = rect.top + rect.height / 2;
    const points: [number, number][] = [
        [left, top],
        [midX, top],
        [right, top],
        [right, midY],
        [right, bottom],
        [midX, bottom],
        [left, bottom],
        [left, midY],
    ];

    return points
        .map(([x, y]) => [Math.round(x), Math.round(y)] as [number, number])
        .filter(([x, y]) => x >= 0 && y >= 0 && x < viewport.width && y < viewport.height);
}

/** What the engine needs from the page; the browser's in `pageSampler()`, fakes in tests. */
export interface Sampler {
    /** Page elements at a point, topmost first (Lens's own left out). */
    stackAt(x: number, y: number): Element[];
    style(el: Element): Pick<CSSStyleDeclaration, 'backgroundColor' | 'backgroundImage' | 'opacity' | 'visibility'>;
    /** The colour of a media element (img, video, canvas) at a point, or null if it can't be read. */
    media(el: Element, x: number, y: number): Rgba | null;
    /** The colour of an element's background image at a point (null while loading or unreadable). */
    backgroundImage(el: Element, url: string, x: number, y: number): Rgba | null;
    color(value: string): Rgba | null;
}

const MEDIA = new Set(['IMG', 'VIDEO', 'CANVAS']);

/** The colour behind one point, layers blended. */
export function colorAt(sampler: Sampler, x: number, y: number): Rgb {
    const layers: Rgba[] = [];
    for (const el of sampler.stackAt(x, y)) {
        const style = sampler.style(el);
        if (style.visibility === 'hidden' || parseFloat(style.opacity) === 0) {
            continue;
        }
        if (MEDIA.has(el.tagName)) {
            const pixel = sampler.media(el, x, y);
            if (pixel) {
                layers.push(pixel);
                if (pixel.a >= 0.999) {
                    break;
                }
            }
            continue;
        }
        const image = style.backgroundImage;
        if (image && image !== 'none') {
            const url = /url\((['"]?)(.*?)\1\)/.exec(image)?.[2];
            const fromImage = url ? sampler.backgroundImage(el, url, x, y) : null;
            const fromStops = fromImage ?? average(gradientStops(image).flatMap((stop) => sampler.color(stop) ?? []));
            if (fromStops && fromStops.a > 0) {
                layers.push(fromStops);
                if (fromStops.a >= 0.999) {
                    break;
                }
            }
        }
        const background = sampler.color(style.backgroundColor);
        if (background && background.a > 0) {
            layers.push(background);
            if (background.a >= 0.999) {
                break;
            }
        }
    }

    return composite(layers);
}

/** The ring for an element: what's behind the points around it, averaged. */
export function ringColors(
    sampler: Sampler,
    rect: Rect,
    gap: number,
    viewport: { width: number; height: number },
): RingColors {
    const points = ringPoints(rect, gap, viewport);
    const samples =
        points.length > 0
            ? points
            : [[Math.round(rect.left + rect.width / 2), Math.round(rect.top + rect.height / 2)] as [number, number]];
    const lums = samples.map(([x, y]) => luminance(colorAt(sampler, x, y)));

    return ringFor(lums.reduce((a, b) => a + b, 0) / lums.length);
}

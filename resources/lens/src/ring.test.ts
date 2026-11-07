import { describe, expect, it } from 'vitest';
import { luminance } from './contrast';
import {
    colorAt,
    composite,
    gradientStops,
    INK,
    parseColor,
    ringColors,
    ringFor,
    ringPoints,
    WHITE,
    type Sampler,
} from './ring';

describe('picking the ring', () => {
    it('is ink with a white halo on a light background (screen 05: 0.95)', () => {
        expect(ringFor(0.95)).toMatchObject({ ring: INK, halo: WHITE });
    });

    it('is white with an ink halo on a dark one (screen 05: 0.02)', () => {
        expect(ringFor(0.02)).toMatchObject({ ring: WHITE, halo: INK });
    });

    it('takes whichever contrasts more: ink from luminance 0.204 up', () => {
        expect(ringFor(0.2).ring).toBe(WHITE);
        expect(ringFor(0.21).ring).toBe(INK);
        // Screen 05 labels its mid-tone example (0.24) "white wins"; by WCAG contrast ink wins there (4.7 vs 3.6).
        expect(ringFor(0.24).ring).toBe(INK);
    });
});

describe('reading colours', () => {
    it('parses what browsers serialize computed colours as', () => {
        expect(parseColor('rgb(30, 42, 36)')).toEqual({ r: 30, g: 42, b: 36, a: 1 });
        expect(parseColor('rgba(0, 0, 0, 0.5)')).toEqual({ r: 0, g: 0, b: 0, a: 0.5 });
        expect(parseColor('rgb(10 20 30 / 25%)')).toEqual({ r: 10, g: 20, b: 30, a: 0.25 });
        expect(parseColor('#1E2A24')).toEqual({ r: 30, g: 42, b: 36, a: 1 });
        expect(parseColor('#fff8')).toMatchObject({ r: 255, a: 0x88 / 255 });
        expect(parseColor('transparent')).toMatchObject({ a: 0 });
    });

    it('hands anything else to the fallback (a canvas in the browser)', () => {
        expect(parseColor('oklch(0.5 0.1 150)')).toBeNull();
        expect(parseColor('oklch(0.5 0.1 150)', () => ({ r: 1, g: 2, b: 3, a: 1 }))).toEqual({
            r: 1,
            g: 2,
            b: 3,
            a: 1,
        });
    });

    it('finds a gradient’s colour stops', () => {
        expect(gradientStops('linear-gradient(135deg, oklch(0.55 0.05 150) 0%, rgb(124, 140, 126) 100%)')).toEqual([
            'oklch(0.55 0.05 150)',
            'rgb(124, 140, 126)',
        ]);
    });

    it('blends translucent layers over what’s under them, white at the bottom', () => {
        expect(composite([])).toEqual([255, 255, 255]);
        expect(composite([{ r: 0, g: 0, b: 0, a: 0.5 }])).toEqual([127.5, 127.5, 127.5]);
        expect(
            composite([
                { r: 0, g: 0, b: 0, a: 0.5 },
                { r: 0, g: 0, b: 0, a: 1 },
            ]),
        ).toEqual([0, 0, 0]);
    });
});

/** A page made of named layers; the stack at every point is the same. */
function fakePage(
    layers: { tag?: string; bg?: string; image?: string; hidden?: boolean; pixel?: [number, number, number] }[],
): Sampler {
    const elements = layers.map((layer) => {
        const el = document.createElement(layer.tag ?? 'div');
        return { el, layer };
    });
    return {
        stackAt: () => elements.map((e) => e.el),
        style: (el) => {
            const { layer } = elements.find((e) => e.el === el)!;
            return {
                backgroundColor: layer.bg ?? 'rgba(0, 0, 0, 0)',
                backgroundImage: layer.image ?? 'none',
                opacity: '1',
                visibility: layer.hidden ? 'hidden' : 'visible',
            };
        },
        media: (el) => {
            const { layer } = elements.find((e) => e.el === el)!;
            return layer.pixel ? { r: layer.pixel[0], g: layer.pixel[1], b: layer.pixel[2], a: 1 } : null;
        },
        backgroundImage: () => null,
        color: (value) => parseColor(value),
    };
}

describe('what’s behind the ring', () => {
    it('is the first painted background colour', () => {
        expect(colorAt(fakePage([{}, { bg: 'rgb(30, 42, 36)' }, { bg: '#fff' }]), 0, 0)).toEqual([30, 42, 36]);
    });

    it('skips hidden layers and blends translucent ones', () => {
        const rgb = colorAt(
            fakePage([{ bg: '#000', hidden: true }, { bg: 'rgba(0, 0, 0, 0.5)' }, { bg: '#fff' }]),
            0,
            0,
        );
        expect(rgb).toEqual([127.5, 127.5, 127.5]);
    });

    it('reads an image’s pixels, and moves on when it can’t', () => {
        expect(colorAt(fakePage([{ tag: 'img', pixel: [20, 20, 20] }, { bg: '#fff' }]), 0, 0)).toEqual([20, 20, 20]);
        expect(colorAt(fakePage([{ tag: 'img' }, { bg: 'rgb(1, 2, 3)' }]), 0, 0)).toEqual([1, 2, 3]);
    });

    it('averages a gradient’s stops when there’s no image to read', () => {
        const rgb = colorAt(fakePage([{ image: 'linear-gradient(rgb(0, 0, 0), rgb(100, 100, 100))' }]), 0, 0);
        expect(rgb).toEqual([50, 50, 50]);
    });

    it('gives a dark section a white ring', () => {
        const page = fakePage([{ bg: '#1E2A24' }]);
        const colors = ringColors(page, { left: 100, top: 100, width: 200, height: 40 }, 7, {
            width: 1440,
            height: 900,
        });
        expect(colors.ring).toBe(WHITE);
        expect(colors.luminance).toBeCloseTo(luminance([30, 42, 36]), 5);
    });
});

it('samples around the ring, inside the viewport only', () => {
    expect(ringPoints({ left: 10, top: 10, width: 100, height: 50 }, 7, { width: 1440, height: 900 })).toHaveLength(8);
    const atEdge = ringPoints({ left: 0, top: 0, width: 100, height: 50 }, 7, { width: 1440, height: 900 });
    expect(atEdge.every(([x, y]) => x >= 0 && y >= 0)).toBe(true);
    expect(atEdge).toHaveLength(3);
});

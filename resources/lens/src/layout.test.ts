import { describe, expect, it } from 'vitest';
import {
    clampSheet,
    defaultSheet,
    dockZone,
    nearestSide,
    pageArea,
    pushCss,
    ringBox,
    sheetHeight,
    type Viewport,
} from './layout';

const desktop: Viewport = { width: 1440, height: 900, top: 32 };

describe('dockZone', () => {
    it('offers the left edge to a sheet within reach of it (screen 02: left 96)', () => {
        expect(dockZone(96, desktop)).toBe('left');
        expect(dockZone(119, desktop)).toBe('left');
    });

    it('offers the right edge the same way', () => {
        expect(dockZone(1440 - 400 - 100, desktop)).toBe('right');
    });

    it('offers nothing in the middle', () => {
        expect(dockZone(120, desktop)).toBeNull();
        expect(dockZone(500, desktop)).toBeNull();
        expect(dockZone(1440 - 400 - 120, desktop)).toBeNull();
    });
});

describe('clampSheet', () => {
    it('leaves a sheet on screen alone', () => {
        expect(clampSheet({ x: 200, y: 150 }, desktop)).toEqual({ x: 200, y: 150 });
    });

    it('never puts the header under the admin bar', () => {
        expect(clampSheet({ x: 200, y: -40 }, desktop).y).toBe(44);
    });

    it('keeps the whole sheet on screen, composer included', () => {
        expect(clampSheet({ x: -2000, y: 100 }, desktop).x).toBe(12);
        expect(clampSheet({ x: 5000, y: 5000 }, desktop)).toEqual({ x: 1440 - 400 - 12, y: 900 - 600 - 12 });
        const short = { width: 900, height: 560, top: 32 };
        const sheet = clampSheet({ x: 461, y: 104 }, short);
        expect(sheet.y + sheetHeight(short)).toBeLessThanOrEqual(560 - 12);
    });

    it('pins a sheet wider than the window to the left edge', () => {
        expect(clampSheet({ x: 100, y: 100 }, { width: 380, height: 700, top: 0 }).x).toBe(12);
    });

    it('lets a dragged sheet reach both dock zones', () => {
        expect(dockZone(clampSheet({ x: -500, y: 100 }, desktop).x, desktop)).toBe('left');
        expect(dockZone(clampSheet({ x: 5000, y: 100 }, desktop).x, desktop)).toBe('right');
    });

    it('rounds to whole pixels', () => {
        expect(clampSheet({ x: 200.6, y: 150.2 }, desktop)).toEqual({ x: 201, y: 150 });
    });
});

it('places a new sheet near the right edge, under the admin bar', () => {
    expect(defaultSheet(desktop)).toEqual({ x: 1016, y: 104 });
});

it('makes the sheet 600px tall, or what fits', () => {
    expect(sheetHeight(desktop)).toBe(600);
    expect(sheetHeight({ width: 1024, height: 560, top: 0 })).toBe(536);
    expect(sheetHeight({ width: 400, height: 200, top: 46 })).toBe(240);
});

it('docks to the nearer side', () => {
    expect(nearestSide({ x: 100, y: 0 }, desktop)).toBe('left');
    expect(nearestSide({ x: 600, y: 0 }, desktop)).toBe('right');
});

it('pushes the page on the docked side only', () => {
    expect(pushCss('right')).toBe('html { margin-right: 400px !important; }');
    expect(pushCss('left')).toBe('html { margin-left: 400px !important; }');
});

it('knows where the page shows beside a docked panel', () => {
    expect(pageArea(desktop, 'right')).toEqual({ left: 0, top: 32, right: 1040, bottom: 900 });
    expect(pageArea(desktop, 'left')).toEqual({ left: 400, top: 32, right: 1440, bottom: 900 });
    expect(pageArea(desktop, null)).toEqual({ left: 0, top: 32, right: 1440, bottom: 900 });
});

it('keeps a ring inside the page area, all four sides showing', () => {
    const area = pageArea(desktop, 'right');
    expect(ringBox({ left: 100, top: 100, width: 200, height: 50 }, 7, area)).toEqual({
        left: 93,
        top: 93,
        width: 214,
        height: 64,
    });
    expect(ringBox({ left: 0, top: 20, width: 1040, height: 300 }, 7, area)).toEqual({
        left: 1,
        top: 33,
        width: 1038,
        height: 294,
    });
});

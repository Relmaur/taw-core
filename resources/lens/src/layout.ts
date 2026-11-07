/**
 * Panel geometry, kept pure so it's tested without a browser: where a dragged sheet docks, where it may
 * sit, and how much room the docked panel takes from the page.
 */
import type { Side } from './prefs';

export const PANEL_WIDTH = 400;
export const SHEET_HEIGHT = 600;
/** Space kept between a sheet and the viewport edge. */
export const SHEET_MARGIN = 12;
/** A sheet whose edge comes this close to the viewport's left or right edge docks there when dropped. */
export const DOCK_REACH = 120;

export interface Viewport {
    width: number;
    height: number;
    /** Space at the top taken by WordPress's admin bar (0 without one). */
    top: number;
}

export interface Point {
    x: number;
    y: number;
}

/** The side a sheet at `x` would dock to if dropped now, or null. */
export function dockZone(x: number, viewport: Viewport, width = PANEL_WIDTH): Side | null {
    if (x < DOCK_REACH) {
        return 'left';
    }
    if (x + width > viewport.width - DOCK_REACH) {
        return 'right';
    }

    return null;
}

/** The sheet's height: 600px, or what fits. */
export const sheetHeight = (viewport: Viewport): number =>
    Math.max(240, Math.min(SHEET_HEIGHT, viewport.height - viewport.top - 2 * SHEET_MARGIN));

/** Where a sheet first appears: near the right edge, below the admin bar. */
export function defaultSheet(viewport: Viewport): Point {
    return clampSheet({ x: viewport.width - PANEL_WIDTH - 24, y: viewport.top + 72 }, viewport);
}

/**
 * Keeps the whole sheet on screen, below the admin bar, with a small margin (a window narrower than the
 * sheet pins it to the left edge). Both dock zones stay reachable: they start 120px from each edge.
 */
export function clampSheet(point: Point, viewport: Viewport, width = PANEL_WIDTH): Point {
    const minX = SHEET_MARGIN;
    const maxX = Math.max(minX, viewport.width - width - SHEET_MARGIN);
    const minY = viewport.top + SHEET_MARGIN;
    const maxY = Math.max(minY, viewport.height - sheetHeight(viewport) - SHEET_MARGIN);

    return {
        x: Math.round(Math.min(maxX, Math.max(minX, point.x))),
        y: Math.round(Math.min(maxY, Math.max(minY, point.y))),
    };
}

/** The side nearer the sheet's centre: where "Dock as Inspector" sends it. */
export const nearestSide = (sheet: Point, viewport: Viewport, width = PANEL_WIDTH): Side =>
    sheet.x + width / 2 < viewport.width / 2 ? 'left' : 'right';

/**
 * The stylesheet that makes room for the docked panel. Docked, Lens pushes the page instead of covering it:
 * the document gets a margin on the docked side, so the site lays out in the remaining width.
 */
export function pushCss(side: Side, width = PANEL_WIDTH): string {
    return `html { margin-${side}: ${width}px !important; }`;
}

/** The part of the viewport the page shows: the docked panel and the admin bar left out. */
export interface Area {
    left: number;
    top: number;
    right: number;
    bottom: number;
}

/** The page area beside a docked panel (or the whole viewport below the admin bar). */
export function pageArea(viewport: Viewport, docked: Side | null, width = PANEL_WIDTH): Area {
    return {
        left: docked === 'left' ? width : 0,
        top: viewport.top,
        right: docked === 'right' ? viewport.width - width : viewport.width,
        bottom: viewport.height,
    };
}

/**
 * A ring's box: the element's rect grown by `outset`, then kept 1px inside the page area, so a full-width or
 * partly scrolled-away element still shows all four sides of its ring.
 */
export function ringBox(
    rect: { left: number; top: number; width: number; height: number },
    outset: number,
    area: Area,
): { left: number; top: number; width: number; height: number } {
    const left = Math.max(area.left + 1, rect.left - outset);
    const top = Math.max(area.top + 1, rect.top - outset);
    const right = Math.min(area.right - 1, rect.left + rect.width + outset);
    const bottom = Math.min(area.bottom - 1, rect.top + rect.height + outset);

    return { left, top, width: Math.max(0, right - left), height: Math.max(0, bottom - top) };
}

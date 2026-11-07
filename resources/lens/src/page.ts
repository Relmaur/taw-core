/**
 * Lens's reach into the page under it, all in one place: reading colours for the contrast engine, the
 * picker's event capture, and the source map. Nothing here changes the site's markup or its elements'
 * styles; while picking, one <style> sets the crosshair cursor, removed when picking stops.
 */
import { readManifest } from './manifest';
import { parseColor, type Rgba, type Sampler } from './ring';
import { PageMap } from './sourcemap';

const PICK_STYLE_ID = 'taw-lens-pick';
const GRID = 24;

/** Pixels of an image drawn small, for sampling; null when the browser won't let a page read them. */
type Pixels = { data: Uint8ClampedArray } | null;

function readPixels(doc: Document, source: CanvasImageSource): Pixels {
    try {
        const canvas = doc.createElement('canvas');
        canvas.width = GRID;
        canvas.height = GRID;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) {
            return null;
        }
        ctx.drawImage(source, 0, 0, GRID, GRID);

        return { data: ctx.getImageData(0, 0, GRID, GRID).data };
    } catch {
        // A cross-origin image without CORS taints the canvas: its pixels stay unknown.
        return null;
    }
}

function pixelAt(pixels: Pixels, rect: DOMRect, x: number, y: number): Rgba | null {
    if (!pixels || rect.width <= 0 || rect.height <= 0) {
        return null;
    }
    const gx = Math.min(GRID - 1, Math.max(0, Math.floor(((x - rect.left) / rect.width) * GRID)));
    const gy = Math.min(GRID - 1, Math.max(0, Math.floor(((y - rect.top) / rect.height) * GRID)));
    const i = (gy * GRID + gx) * 4;
    const d = pixels.data;

    return { r: d[i], g: d[i + 1], b: d[i + 2], a: d[i + 3] / 255 };
}

/**
 * The contrast engine's view of the real page. `onAsset` runs when a background image it needed finishes
 * loading, so the rings can be re-read.
 */
export function pageSampler(doc: Document, host: Element, onAsset: () => void): Sampler {
    const win = doc.defaultView ?? window;
    const media = new WeakMap<Element, { key: string; pixels: Pixels }>();
    const backgrounds = new Map<string, { image: HTMLImageElement; pixels: Pixels; ready: boolean }>();
    const colors = new Map<string, Rgba | null>();
    let canvas: CanvasRenderingContext2D | null | undefined;

    const viaCanvas = (value: string): Rgba | null => {
        if (canvas === undefined) {
            canvas = doc.createElement('canvas').getContext('2d', { willReadFrequently: true });
        }
        if (!canvas) {
            return null;
        }
        canvas.clearRect(0, 0, 1, 1);
        canvas.fillStyle = '#000';
        canvas.fillStyle = value;
        canvas.fillRect(0, 0, 1, 1);
        const [r, g, b, a] = canvas.getImageData(0, 0, 1, 1).data;

        return { r, g, b, a: a / 255 };
    };

    return {
        stackAt: (x, y) => doc.elementsFromPoint(x, y).filter((el) => el !== host && !host.contains(el)),
        style: (el) => win.getComputedStyle(el),
        color(value) {
            if (!colors.has(value)) {
                colors.set(value, parseColor(value, viaCanvas));
            }

            return colors.get(value) ?? null;
        },
        media(el, x, y) {
            const source = el as HTMLImageElement | HTMLVideoElement | HTMLCanvasElement;
            const key = (el as HTMLImageElement).currentSrc || (el as HTMLImageElement).src || 'canvas';
            if (el.tagName === 'IMG' && !(el as HTMLImageElement).complete) {
                return null;
            }
            let entry = media.get(el);
            if (!entry || entry.key !== key) {
                entry = { key, pixels: readPixels(doc, source) };
                media.set(el, entry);
            }

            return pixelAt(entry.pixels, el.getBoundingClientRect(), x, y);
        },
        backgroundImage(el, url, x, y) {
            let entry = backgrounds.get(url);
            if (!entry) {
                const image = new Image();
                const created = { image, pixels: null as Pixels, ready: false };
                image.crossOrigin = 'anonymous';
                image.onload = () => {
                    created.pixels = readPixels(doc, image);
                    created.ready = true;
                    onAsset();
                };
                image.onerror = () => {
                    created.ready = true;
                };
                image.src = new URL(url, doc.baseURI).href;
                backgrounds.set(url, created);
                entry = created;
            }

            return entry.ready ? pixelAt(entry.pixels, el.getBoundingClientRect(), x, y) : null;
        },
    };
}

/** What the picker reports. */
export interface PickHandlers {
    hover(el: Element | null): void;
    pick(el: Element, add: boolean): void;
    stop(): void;
}

const BLOCKED = ['pointerdown', 'pointerup', 'mousedown', 'mouseup', 'click', 'dblclick', 'auxclick', 'touchstart'];

/**
 * Picking: hovering a page element reports it; clicking selects it (shift adds) instead of doing what the
 * page would do, so links don't navigate and buttons don't fire. Esc stops. Lens's own panel keeps working.
 * Returns the function that stops listening.
 */
export function startPicking(doc: Document, host: Element, handlers: PickHandlers): () => void {
    const win = doc.defaultView ?? window;
    const fromLens = (event: Event) => event.composedPath().includes(host);
    const pageTarget = (event: Event): Element | null => {
        const target = event.target;
        if (!(target instanceof Element) || target === host || host.contains(target)) {
            return null;
        }
        return target === doc.documentElement ? null : target;
    };

    const onMove = (event: PointerEvent) => handlers.hover(fromLens(event) ? null : pageTarget(event));
    const onLeave = (event: MouseEvent) => {
        if (!event.relatedTarget) {
            handlers.hover(null);
        }
    };
    const block = (event: Event) => {
        if (fromLens(event)) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        if (event.type === 'click') {
            const target = pageTarget(event);
            if (target) {
                handlers.pick(target, (event as MouseEvent).shiftKey);
            }
        }
    };
    const onKey = (event: KeyboardEvent) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopImmediatePropagation();
            handlers.stop();
        }
    };

    let style = doc.getElementById(PICK_STYLE_ID);
    if (!style) {
        style = doc.createElement('style');
        style.id = PICK_STYLE_ID;
        style.textContent = 'html, html * { cursor: crosshair !important; }';
        doc.head.appendChild(style);
    }

    win.addEventListener('pointermove', onMove, true);
    doc.addEventListener('mouseout', onLeave, true);
    for (const type of BLOCKED) {
        win.addEventListener(type, block, { capture: true, passive: false });
    }
    win.addEventListener('keydown', onKey, true);

    return () => {
        win.removeEventListener('pointermove', onMove, true);
        doc.removeEventListener('mouseout', onLeave, true);
        for (const type of BLOCKED) {
            win.removeEventListener(type, block, { capture: true });
        }
        win.removeEventListener('keydown', onKey, true);
        doc.getElementById(PICK_STYLE_ID)?.remove();
        handlers.hover(null);
    };
}

/** The page's source map, read when first needed (null on a page without one). */
export function pageMap(doc: Document, host: Element): PageMap | null {
    const manifest = readManifest(doc);

    return manifest ? new PageMap(doc, manifest, host) : null;
}

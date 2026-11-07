/**
 * The rings and label chips on the page (component 02, screen 05). An overlay in Lens's shadow root: fixed,
 * click-through, above the site and below the panel. Nothing is added to or changed on the site's elements.
 *
 * Hover: a 1px ring. Selected: a 2px ring 5px outside the element, with a halo of the other colour. The label
 * chip sits above the ring (below it when there's no room) in the ring's colour.
 */
import { useEffect, useMemo, useReducer, useRef } from 'preact/hooks';
import { ringBox, type Area } from './layout';
import { ringColors, type RingColors, type Sampler } from './ring';

export interface RingSpec {
    key: string;
    el: Element;
    state: 'hover' | 'selected';
    label?: string;
}

/** How far each ring's outer edge sits outside the element. */
const OUTSET = { hover: 4, selected: 7 } as const;
const CHIP_HEIGHT = 24;

export function Overlay({
    rings,
    sampler,
    top,
    area,
    refresh = 0,
}: {
    rings: RingSpec[];
    sampler: Sampler | null;
    /** Space at the top taken by WordPress's admin bar: chips stay below it. */
    top: number;
    /** Where the page shows (beside a docked panel): rings and chips stay inside it. */
    area?: Area;
    /** Changes when an image the engine needed has loaded: colours are read again. */
    refresh?: number;
}) {
    const [tick, redraw] = useReducer((n: number) => n + 1, 0);
    const colors = useRef(new Map<Element, RingColors>());
    const live = rings.length > 0;

    const readAt = useRef(refresh);

    // Follow the page: scroll and resize move rings at once; colours are read again once it settles.
    useEffect(() => {
        if (!live) {
            return;
        }
        let frame = 0;
        let settle = 0;
        const move = () => {
            if (!frame) {
                frame = requestAnimationFrame(() => {
                    frame = 0;
                    redraw(undefined);
                });
            }
            clearTimeout(settle);
            settle = window.setTimeout(() => {
                colors.current.clear();
                redraw(undefined);
            }, 150);
        };
        window.addEventListener('scroll', move, { capture: true, passive: true });
        window.addEventListener('resize', move, { passive: true });
        // Layout can shift with no scroll (images loading, menus opening): a slow check catches it.
        const poll = window.setInterval(() => redraw(undefined), 600);

        return () => {
            cancelAnimationFrame(frame);
            clearTimeout(settle);
            clearInterval(poll);
            window.removeEventListener('scroll', move, { capture: true });
            window.removeEventListener('resize', move);
        };
    }, [live]);

    const viewport = { width: document.documentElement.clientWidth || window.innerWidth, height: window.innerHeight };
    const drawn = useMemo(
        () => {
            if (readAt.current !== refresh) {
                readAt.current = refresh;
                colors.current.clear();
            }

            return rings.flatMap((spec) => {
                if (!spec.el.isConnected) {
                    return [];
                }
                const rect = spec.el.getBoundingClientRect();
                if (rect.width <= 0 && rect.height <= 0) {
                    return [];
                }
                const outset = OUTSET[spec.state];
                if (rect.bottom + outset < 0 || rect.top - outset > viewport.height) {
                    return [];
                }
                let color = colors.current.get(spec.el);
                if (!color) {
                    color = sampler
                        ? ringColors(sampler, rect, outset, viewport)
                        : { ring: '#1A1C21', halo: '#FFFFFF', luminance: 1 };
                    colors.current.set(spec.el, color);
                }

                return [{ spec, rect, outset, color }];
            });
        },
        // `tick` and `refresh` re-measure on scroll, resize, the poll and a loaded image.
        [rings, sampler, tick, refresh, viewport.width, viewport.height],
    );

    if (drawn.length === 0) {
        return null;
    }

    return (
        <div class="overlay" aria-hidden="true">
            {drawn.map(({ spec, rect, outset, color }) => {
                const bounds = area ?? { left: 0, top, right: viewport.width, bottom: viewport.height };
                const box = ringBox(rect, outset, bounds);
                if (box.width <= 0 || box.height <= 0) {
                    return null;
                }
                const above = box.top - 6 - CHIP_HEIGHT;
                const chipTop =
                    above >= bounds.top + 2
                        ? above
                        : Math.min(box.top + box.height + 6, bounds.bottom - CHIP_HEIGHT - 2);
                const chipLeft = Math.max(bounds.left + 4, Math.min(box.left, bounds.right - 120));

                return (
                    <div key={spec.key}>
                        <div
                            class="ring"
                            data-state={spec.state}
                            style={{
                                left: `${box.left}px`,
                                top: `${box.top}px`,
                                width: `${box.width}px`,
                                height: `${box.height}px`,
                                '--ring': color.ring,
                                '--halo': color.halo,
                            }}
                        />
                        {spec.label && (
                            <div
                                class="ring-label"
                                style={{
                                    left: `${chipLeft}px`,
                                    top: `${chipTop}px`,
                                    maxWidth: `${Math.max(120, bounds.right - chipLeft - 8)}px`,
                                    '--ring': color.ring,
                                    '--halo': color.halo,
                                }}
                            >
                                {spec.label}
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

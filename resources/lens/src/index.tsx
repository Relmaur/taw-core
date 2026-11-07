/**
 * TAW Lens page script (umbrella ADR-0006, taw/core ADR-0020). PHP loads it only under the Lens gate
 * (TAW\Core\Lens\Lens): the Vite dev server running, the theme's "Enable Lens" toggle, a user who can
 * edit the theme.
 *
 * Everything Lens draws lives in one shadow root on a `<taw-lens>` element at the end of <html>, so the
 * site's styles never reach it and its styles never reach the site. The only thing it changes on the page
 * itself is the margin that makes room for the docked panel (one <style> element, removed when it undocks).
 */
import { render } from 'preact';
import { Panel, type PageEnv, type PanelEnv } from './Panel';
import { pushCss } from './layout';
import { pageMap, pageSampler, startPicking } from './page';
import { loadPrefs, savePrefs } from './prefs';
import { panelCss } from './styles';

export { tokensCss } from './tokens';

declare global {
    interface Window {
        tawLens?: { host?: string; version?: string; editor?: string };
    }
}

export const HOST_TAG = 'taw-lens';
const PUSH_ID = 'taw-lens-push';
const EDITOR_URL = 'vscode://file/{path}:{line}';

/** The page under the panel: its source map, the picker and the contrast engine. */
export function pageAccess(doc: Document, host: HTMLElement, editor = EDITOR_URL): PageEnv {
    const win = doc.defaultView ?? window;
    const assetListeners = new Set<() => void>();
    let map: ReturnType<typeof pageMap> | undefined;

    return {
        map(fresh = false) {
            if (fresh || map === undefined) {
                map = pageMap(doc, host);
            }
            return map;
        },
        pick: (handlers) => startPicking(doc, host, handlers),
        sampler: pageSampler(doc, host, () => assetListeners.forEach((listener) => listener())),
        onAsset(callback) {
            assetListeners.add(callback);
            return () => assetListeners.delete(callback);
        },
        reveal(el) {
            const rect = el.getBoundingClientRect();
            if (rect.bottom < 0 || rect.top > win.innerHeight) {
                el.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        },
        editor,
    };
}

/** The page around the panel: the real document. */
export function pageEnv(doc: Document, host: HTMLElement): PanelEnv {
    const win = doc.defaultView ?? window;

    return {
        viewport() {
            // WordPress's admin bar is fixed to the top for logged-in users: the panel starts under it.
            const bar = doc.getElementById('wpadminbar');
            const top =
                bar && win.getComputedStyle(bar).position === 'fixed'
                    ? Math.max(0, bar.getBoundingClientRect().bottom)
                    : 0;

            return { width: doc.documentElement.clientWidth || win.innerWidth, height: win.innerHeight, top };
        },
        push(side) {
            let style = doc.getElementById(PUSH_ID);
            if (side === null) {
                style?.remove();

                return;
            }
            if (!style) {
                style = doc.createElement('style');
                style.id = PUSH_ID;
                doc.head.appendChild(style);
            }
            style.textContent = pushCss(side);
        },
        theme(choice) {
            if (choice === 'system') {
                host.removeAttribute('data-theme');
            } else {
                host.setAttribute('data-theme', choice);
            }
        },
        save: (prefs) => savePrefs(prefs),
        onResize(callback) {
            win.addEventListener('resize', callback);

            return () => win.removeEventListener('resize', callback);
        },
        page: pageAccess(doc, host, window.tawLens?.editor || EDITOR_URL),
    };
}

/** Mounts Lens once; a second call returns the existing host. */
export function mount(doc: Document = document): HTMLElement {
    const existing = doc.querySelector<HTMLElement>(HOST_TAG);
    if (existing) {
        return existing;
    }
    const host = doc.createElement(HOST_TAG);
    doc.documentElement.appendChild(host);
    const root = host.attachShadow({ mode: 'open' });
    const style = doc.createElement('style');
    style.textContent = panelCss();
    root.appendChild(style);
    const app = doc.createElement('div');
    root.appendChild(app);

    const fallbackHost = doc.defaultView?.location.host ?? '';
    render(<Panel host={window.tawLens?.host || fallbackHost} initial={loadPrefs()} env={pageEnv(doc, host)} />, app);

    return host;
}

if (typeof document !== 'undefined' && !import.meta.env?.VITEST) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => mount(), { once: true });
    } else {
        mount();
    }
}

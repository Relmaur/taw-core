/**
 * Registers the `taw/field` Block Bindings source in the block editor (taw/core
 * ADR-0010). Loaded in the post and site editors by Bindings::enqueueEditor(),
 * with window.tawBindings (the source name, the preview route and the fields).
 */
import apiFetch from '@wordpress/api-fetch';
import { registerBlockBindingsSource } from '@wordpress/blocks';
import { createReduxStore, dispatch, register, select, subscribe } from '@wordpress/data';
import { registerPlugin } from '@wordpress/plugins';
import { registerAllowBound } from './allowBound';
import { batcher, source, STORE, type Config, type PreviewItem } from './logic';
import { connectMenu } from './menu';
import { registerToolbar } from './toolbar';

declare global {
    interface Window {
        tawBindings?: Config;
    }
}

const config = window.tawBindings;

if (config) {
    const fetchValue = batcher((items: PreviewItem[]) =>
        apiFetch<{ values: Record<string, unknown> }>({ path: config.route, method: 'POST', data: { items } }).then(
            (response) => response.values ?? {},
        ),
    );

    type State = { values: Record<string, unknown> };

    const store = createReduxStore(STORE, {
        reducer(state: State = { values: {} }, action: { type: string; key?: string; value?: unknown }): State {
            if (action.type === 'RECEIVE' && action.key !== undefined) {
                return { values: { ...state.values, [action.key]: action.value } };
            }
            return state;
        },
        actions: {
            receive: (key: string, value: unknown) => ({ type: 'RECEIVE', key, value }),
        },
        selectors: {
            getValue: (state: State, item: PreviewItem) => state.values[item.key],
        },
        resolvers: {
            getValue:
                (item: PreviewItem) =>
                async ({ dispatch }: { dispatch: { receive: (key: string, value: unknown) => void } }) => {
                    try {
                        dispatch.receive(item.key, await fetchValue(item));
                    } catch {
                        dispatch.receive(item.key, null);
                    }
                },
        },
    });
    register(store);

    registerBlockBindingsSource(source(config));

    // "Connect to TAW field…" in each block's Options (⋮) menu.
    registerPlugin('taw-bindings-menu', { render: connectMenu(config) });
    // A "TAW field" dropdown in the block toolbar itself.
    registerToolbar(config);
    // Bound-only blocks from the editing policy (allowBound), when there are any.
    registerAllowBound(config);

    // Previews show saved values: refresh them after each (non-auto) save.
    let wasSaving = false;
    subscribe(() => {
        const editor = select('core/editor');
        const saving = Boolean(editor?.isSavingPost?.()) && !editor?.isAutosavingPost?.();
        const finished = wasSaving && !saving;
        // Before invalidating: that dispatch notifies this listener again.
        wasSaving = saving;
        if (finished) {
            dispatch(STORE).invalidateResolutionForStore();
        }
    });
}

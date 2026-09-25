/**
 * Types for the WordPress packages the panel imports but doesn't install
 * (they resolve to window.wp.* through taw/core's wordpressExternals()).
 * Only what the panel uses.
 */
declare module '@wordpress/plugins' {
    import type { ComponentType } from 'react';
    export function registerPlugin(name: string, settings: { render: ComponentType; icon?: unknown }): unknown;
}

declare module '@wordpress/editor' {
    import type { ReactNode } from 'react';
    export function PluginSidebar(props: {
        name: string;
        title: string;
        icon?: unknown;
        children?: ReactNode;
    }): JSX.Element;
    export function PluginSidebarMoreMenuItem(props: {
        target: string;
        icon?: unknown;
        children?: ReactNode;
    }): JSX.Element;
}

declare module '@wordpress/data' {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    type Select = (store: string) => any;
    export function useSelect<T>(mapSelect: (select: Select) => T, deps?: unknown[]): T;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    export function useDispatch(store: string): any;
}

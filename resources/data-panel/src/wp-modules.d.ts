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

declare module '@wordpress/blocks' {
    export interface BlockInstance {
        clientId: string;
        name: string;
        attributes: Record<string, unknown>;
        innerBlocks: BlockInstance[];
    }
    export function rawHandler(args: { HTML: string }): BlockInstance[];
    export function serialize(blocks: BlockInstance[]): string;
    export function createBlock(name: string, attributes?: Record<string, unknown>): BlockInstance;
}

declare module '@wordpress/block-editor' {
    import type { ComponentType, ReactNode } from 'react';
    import type { BlockInstance } from '@wordpress/blocks';
    export interface MediaItem {
        id: number;
        url?: string;
        title?: string;
        filename?: string;
        mime?: string;
        type?: string;
        sizes?: Record<string, { url: string }>;
    }
    export const MediaUpload: ComponentType<{
        onSelect: (media: MediaItem | MediaItem[]) => void;
        allowedTypes?: string[];
        multiple?: boolean | 'add';
        gallery?: boolean;
        value?: number | number[];
        title?: string;
        render: (args: { open: () => void }) => ReactNode;
    }>;
    export const MediaUploadCheck: ComponentType<{ fallback?: ReactNode; children?: ReactNode }>;
    export const BlockEditorProvider: ComponentType<{
        value: BlockInstance[];
        onInput: (blocks: BlockInstance[]) => void;
        onChange: (blocks: BlockInstance[]) => void;
        settings?: Record<string, unknown>;
        children?: ReactNode;
    }>;
    export const BlockCanvas: ComponentType<{ height?: string; styles?: unknown }>;
    export const Inserter: ComponentType<Record<string, unknown>>;
}

declare module '@wordpress/api-fetch' {
    export default function apiFetch<T = unknown>(options: { path: string; method?: string }): Promise<T>;
}

declare module '@wordpress/autop' {
    export function autop(text: string): string;
    export function removep(html: string): string;
}

declare module '@wordpress/html-entities' {
    export function decodeEntities(text: string): string;
}

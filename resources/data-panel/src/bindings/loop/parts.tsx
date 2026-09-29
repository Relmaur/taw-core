/**
 * The TAW Loop's inner blocks in the editor (ADR-0014):
 *
 * - Loop item: the first item is the editable design; the others are the
 *   server's own rendering of the same design for each item (POST
 *   taw/v1/loop/render), refreshed as you edit.
 * - No items: the blocks shown when the loop is empty, always visible here.
 * - Pagination: a sample of the page links.
 */
import React, { useEffect, useMemo, useState } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { BlockContextProvider, InspectorControls, useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';
import { serialize, type BlockInstance } from '@wordpress/blocks';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';
import type { Config } from '../logic';
import { context } from '../preview';
import { loopsFor } from './chain';
import { listClass, listStyle, splitItem, type LoopAttributes } from './data';

interface Preview {
    items: { index: number; html: string; postId: number; postType: string }[];
    total: number;
}

/** The parent loop: its attributes and its inner blocks as saved markup. */
function useLoop(clientId: string): { loopId: string | null; attributes: LoopAttributes | null; content: string } {
    const loopId = useSelect(
        (select) => select('core/block-editor').getBlockRootClientId(clientId) as string | null,
        [clientId],
    );
    const loop = useSelect(
        (select) => (loopId ? (select('core/block-editor').getBlock(loopId) as BlockInstance | null) : null),
        [loopId],
    );
    const content = useMemo(() => (loop ? serialize(loop.innerBlocks) : ''), [loop]);
    return { loopId, attributes: (loop?.attributes as unknown as LoopAttributes) ?? null, content };
}

/** The server's rendering of every item, debounced while editing; stale answers are dropped. */
function usePreview(
    config: Config,
    loopId: string | null,
    attributes: LoopAttributes | null,
    content: string,
): Preview | null {
    const request = useMemo(
        () =>
            attributes?.source?.type
                ? JSON.stringify({ attributes, content, loops: loopsFor(loopId), ...context() })
                : '',
        [attributes, content, loopId],
    );
    const [preview, setPreview] = useState<{ for: string; data: Preview } | null>(null);

    useEffect(() => {
        if (!request || !config.loopRoute) return undefined;
        let current = true;
        const timer = setTimeout(() => {
            void apiFetch<Preview>({
                path: config.loopRoute as string,
                method: 'POST',
                data: JSON.parse(request),
            }).then(
                (data) => {
                    if (current) setPreview({ for: request, data });
                },
                () => {
                    if (current) setPreview({ for: request, data: { items: [], total: 0 } });
                },
            );
        }, 450);
        return () => {
            current = false;
            clearTimeout(timer);
        };
    }, [request, config.loopRoute]);

    // The last answer stays up while a new one loads (no flicker while typing).
    return preview?.data ?? null;
}

export function ItemEdit(config: Config) {
    return function TawLoopItemEdit({ clientId }: { clientId: string }) {
        const { loopId, attributes, content } = useLoop(clientId);
        const preview = usePreview(config, loopId, attributes, content);
        const layout = attributes?.layout;
        const blockProps = useBlockProps({ className: listClass(layout), style: listStyle(layout) });
        const innerBlocksProps = useInnerBlocksProps(
            { className: 'wp-block-taw-loop-item taw-loop-template' },
            { templateLock: false },
        );
        const first = preview?.items[0];
        const others = (preview?.items ?? []).slice(1);
        const total = preview?.total ?? null;

        return (
            <>
                <ul {...blockProps}>
                    {first?.postId ? (
                        // A post item: the editable design reads the first item's post, as core's Query Loop does.
                        <BlockContextProvider value={{ postId: first.postId, postType: first.postType }}>
                            <li {...innerBlocksProps} />
                        </BlockContextProvider>
                    ) : (
                        <li {...innerBlocksProps} />
                    )}
                    {others.map((item) => {
                        const { className, html } = splitItem(item.html);
                        return (
                            <li
                                key={item.index}
                                className={`${className} taw-loop-preview`}
                                aria-hidden="true"
                                // The server's own rendering of this design for the item (editor-only markup).
                                dangerouslySetInnerHTML={{ __html: html }}
                            />
                        );
                    })}
                </ul>
                <p className="taw-loop-note">
                    {total === null
                        ? __('Loading items…', 'taw-core')
                        : total === 0
                          ? __(
                                'No items for this post: the “No items” blocks show instead. The design above is what each item will use.',
                                'taw-core',
                            )
                          : sprintf(
                                /* translators: 1: items shown, 2: all items */
                                _n(
                                    '%1$d item. You’re editing the first; the others follow its design.',
                                    'Showing %1$d of %2$d items. You’re editing the first; the others follow its design.',
                                    total,
                                    'taw-core',
                                ),
                                Math.min(total, preview?.items.length ?? 0),
                                total,
                            )}
                </p>
            </>
        );
    };
}

export function EmptyEdit() {
    const blockProps = useBlockProps({
        className: 'taw-loop-empty-edit',
        'data-label': __('Shown when there are no items', 'taw-core'),
    });
    const innerBlocksProps = useInnerBlocksProps(blockProps, {
        template: [['core/paragraph', { content: __('No items yet.', 'taw-core') }]],
        templateLock: false,
    });
    return <div {...innerBlocksProps} />;
}

interface PaginationProps {
    attributes: { previousLabel: string; nextLabel: string; showNumbers: boolean };
    setAttributes: (next: Partial<PaginationProps['attributes']>) => void;
}

export function PaginationEdit({ attributes, setAttributes }: PaginationProps) {
    const blockProps = useBlockProps({ className: 'taw-loop-pagination-edit' });
    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Page links', 'taw-core')}>
                    <TextControl
                        label={__('“Previous” text', 'taw-core')}
                        placeholder={__('Previous', 'taw-core')}
                        value={attributes.previousLabel}
                        onChange={(previousLabel: string) => setAttributes({ previousLabel })}
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                    />
                    <TextControl
                        label={__('“Next” text', 'taw-core')}
                        placeholder={__('Next', 'taw-core')}
                        value={attributes.nextLabel}
                        onChange={(nextLabel: string) => setAttributes({ nextLabel })}
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                    />
                    <ToggleControl
                        label={__('Show page numbers', 'taw-core')}
                        checked={attributes.showNumbers}
                        onChange={(showNumbers: boolean) => setAttributes({ showNumbers })}
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <nav {...blockProps} aria-label={__('Pagination', 'taw-core')}>
                <span>{attributes.previousLabel || __('Previous', 'taw-core')}</span>
                {attributes.showNumbers && (
                    <>
                        <span className="taw-loop-pagination__number is-current">1</span>
                        <span className="taw-loop-pagination__number">2</span>
                        <span className="taw-loop-pagination__number">3</span>
                    </>
                )}
                <span>{attributes.nextLabel || __('Next', 'taw-core')}</span>
                <em>{__('Shown when the loop has more than one page', 'taw-core')}</em>
            </nav>
        </>
    );
}

import React, { useMemo, useState } from 'react';
import { BlockCanvas, BlockEditorProvider, Inserter } from '@wordpress/block-editor';
import type { BlockInstance } from '@wordpress/blocks';
import { Button, Modal } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import type { FieldDescriptor } from '../types';
import { allowedBlocks, toBlocks, toHtml } from './html';

interface Props {
    field: FieldDescriptor;
    html: string;
    onApply: (html: string) => void;
    onClose: () => void;
}

/**
 * A small block editor in a modal, for one wysiwyg value. The nested
 * BlockEditorProvider keeps its own blocks (a sub-registry), so the post's
 * blocks are never touched. Nothing is written until "Apply", and only when
 * the HTML actually changed.
 */
export default function MiniEditor({ field, html, onApply, onClose }: Props) {
    const initial = useMemo(() => toBlocks(html), [html]);
    const initialHtml = useMemo(() => toHtml(initial), [initial]);
    const [blocks, setBlocks] = useState<BlockInstance[]>(initial);

    const parentSettings = useSelect(
        (select) => (select('core/block-editor')?.getSettings?.() ?? {}) as Record<string, unknown>,
        [],
    );
    const settings = useMemo(
        () => ({
            ...parentSettings,
            allowedBlockTypes: allowedBlocks(field),
            templateLock: false,
            template: undefined,
            hasFixedToolbar: true,
            focusMode: false,
            __experimentalBlockPatterns: [],
            __experimentalBlockPatternCategories: [],
            // The theme's editor styles, so the content looks as it will on the site,
            // plus room around the content inside the canvas.
            styles: [
                ...(Array.isArray(parentSettings.styles) ? parentSettings.styles : []),
                { css: 'body { padding: 16px 24px; }', isGlobalStyles: false },
            ],
        }),
        [parentSettings, field],
    );

    const changed = toHtml(blocks) !== initialHtml;
    const close = () => {
        if (!changed || window.confirm(__('Discard your changes to this field?', 'taw-core'))) {
            onClose();
        }
    };

    return (
        <Modal
            title={field.label ?? field.id}
            className="taw-data-panel__rich-modal"
            size="large"
            onRequestClose={close}
            shouldCloseOnClickOutside={false}
        >
            <BlockEditorProvider value={blocks} onInput={setBlocks} onChange={setBlocks} settings={settings}>
                <div className="taw-data-panel__rich-toolbar">
                    <Inserter
                        rootClientId={undefined}
                        position="bottom right"
                        __experimentalIsQuick={false}
                        isAppender={false}
                    />
                    <span className="taw-data-panel__rich-hint">{__('Type / to choose a block', 'taw-core')}</span>
                </div>
                <div className="taw-data-panel__rich-canvas">
                    <BlockCanvas height="100%" styles={settings.styles} />
                </div>
            </BlockEditorProvider>
            <div className="taw-data-panel__rich-footer">
                <Button variant="tertiary" onClick={close}>
                    {__('Cancel', 'taw-core')}
                </Button>
                <Button
                    variant="primary"
                    onClick={() => {
                        if (changed) onApply(toHtml(blocks));
                        onClose();
                    }}
                >
                    {__('Apply', 'taw-core')}
                </Button>
            </div>
        </Modal>
    );
}

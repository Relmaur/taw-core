/**
 * The database button in the block toolbar: opens the TAW data popup
 * (ADR-0012) for text blocks and connectable blocks, as a dialog in the
 * middle of the screen (its header has the close button; Escape and a click
 * outside close it too). It shows as pressed while the block has a
 * `taw/field` binding.
 */
import React, { useState } from 'react';
import { BlockControls } from '@wordpress/block-editor';
import { getBlockType } from '@wordpress/blocks';
import { Modal, ToolbarButton } from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { CONNECTABLE_BLOCKS, type Bindings, type Config } from './logic';
import { DataPopup } from './popup';

interface EditProps {
    name: string;
    clientId: string;
    isSelected: boolean;
    attributes: { metadata?: { bindings?: Bindings; [key: string]: unknown } };
}

/** Connectable blocks, and any block with rich text (chips go into its text). */
function hasRichText(name: string): boolean {
    const attributes = (getBlockType(name)?.attributes ?? {}) as Record<string, { source?: string }>;
    return Object.values(attributes).some((a) => a?.source === 'rich-text');
}

function DataButton({ config, props }: { config: Config; props: EditProps }) {
    const metadata = props.attributes.metadata ?? {};
    const bound = Object.values(metadata.bindings ?? {}).some((b) => b?.source === config.source);
    const [isOpen, setOpen] = useState(false);
    const close = () => setOpen(false);
    return (
        <>
            <BlockControls group="other">
                <ToolbarButton
                    icon="database"
                    label={__('TAW data', 'taw-core')}
                    isPressed={bound || isOpen}
                    aria-haspopup="dialog"
                    aria-expanded={isOpen}
                    onClick={() => setOpen(!isOpen)}
                />
            </BlockControls>
            {isOpen && (
                <Modal
                    title={__('TAW data', 'taw-core')}
                    __experimentalHideHeader
                    className="taw-data-modal"
                    onRequestClose={close}
                >
                    <DataPopup
                        config={config}
                        clientId={props.clientId}
                        blockName={props.name}
                        metadata={metadata}
                        onClose={close}
                    />
                </Modal>
            )}
        </>
    );
}

export function registerToolbar(config: Config): void {
    addFilter(
        'editor.BlockEdit',
        'taw/bindings-toolbar',
        createHigherOrderComponent(
            (BlockEdit: React.ComponentType<EditProps>) =>
                function WithTawDataButton(props: EditProps) {
                    const eligible =
                        props.isSelected && (CONNECTABLE_BLOCKS.includes(props.name) || hasRichText(props.name));
                    return (
                        <>
                            <BlockEdit {...props} />
                            {eligible && <DataButton config={config} props={props} />}
                        </>
                    );
                },
            'withTawDataButton',
        ),
    );
}

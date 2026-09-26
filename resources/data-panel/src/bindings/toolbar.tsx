/**
 * A "TAW field" button in the block toolbar of connectable blocks: a
 * dropdown of the fields that fit the block (the connected one checked),
 * plus "Disconnect TAW field". Same effect as the Options (⋮) menu's
 * "Connect to TAW field…", one click closer.
 */
import React from 'react';
import { BlockControls } from '@wordpress/block-editor';
import { Dashicon, MenuGroup, MenuItem, ToolbarDropdownMenu } from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useDispatch, useSelect } from '@wordpress/data';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import {
    candidatesFor,
    CONNECTABLE_BLOCKS,
    connectedArgs,
    connectedMetadata,
    sameField,
    withoutTawBindings,
    type Bindings,
    type Config,
} from './logic';

interface EditProps {
    name: string;
    clientId: string;
    isSelected: boolean;
    attributes: { metadata?: { bindings?: Bindings; [key: string]: unknown } };
}

function FieldButton({ config, props }: { config: Config; props: EditProps }) {
    const postType = useSelect((select) => select('core/editor')?.getCurrentPostType?.() as string | undefined, []);
    const { updateBlockAttributes } = useDispatch('core/block-editor');
    const metadata = props.attributes.metadata ?? {};
    const connected = connectedArgs(config.source, metadata.bindings);
    const candidates = candidatesFor(config, props.name, { postType });

    if (candidates.length === 0 && !connected) return null;

    return (
        <BlockControls group="other">
            <ToolbarDropdownMenu
                icon="database"
                label={__('TAW field', 'taw-core')}
                toggleProps={{ className: connected ? 'is-pressed' : undefined }}
            >
                {({ onClose }: { onClose: () => void }) => (
                    <>
                        <MenuGroup label={__('Connect to TAW field', 'taw-core')}>
                            {candidates.map((entry) => {
                                const active = sameField(connected, entry.args);
                                return (
                                    <MenuItem
                                        key={JSON.stringify(entry.args)}
                                        icon={active ? <Dashicon icon="yes" /> : undefined}
                                        isSelected={active}
                                        role="menuitemradio"
                                        onClick={() => {
                                            const next = connectedMetadata(config.source, props.name, metadata, entry);
                                            if (next) updateBlockAttributes(props.clientId, { metadata: next });
                                            onClose();
                                        }}
                                    >
                                        {entry.label}
                                    </MenuItem>
                                );
                            })}
                        </MenuGroup>
                        {connected && (
                            <MenuGroup>
                                <MenuItem
                                    onClick={() => {
                                        updateBlockAttributes(props.clientId, {
                                            metadata: {
                                                ...metadata,
                                                bindings: withoutTawBindings(config.source, metadata.bindings),
                                            },
                                        });
                                        onClose();
                                    }}
                                >
                                    {__('Disconnect TAW field', 'taw-core')}
                                </MenuItem>
                            </MenuGroup>
                        )}
                    </>
                )}
            </ToolbarDropdownMenu>
        </BlockControls>
    );
}

export function registerToolbar(config: Config): void {
    addFilter(
        'editor.BlockEdit',
        'taw/bindings-toolbar',
        createHigherOrderComponent(
            (BlockEdit: React.ComponentType<EditProps>) =>
                function WithTawFieldButton(props: EditProps) {
                    return (
                        <>
                            <BlockEdit {...props} />
                            {props.isSelected && CONNECTABLE_BLOCKS.includes(props.name) && (
                                <FieldButton config={config} props={props} />
                            )}
                        </>
                    );
                },
            'withTawFieldButton',
        ),
    );
}

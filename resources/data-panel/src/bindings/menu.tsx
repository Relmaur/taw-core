/**
 * "Connect to TAW field…" / "Disconnect TAW field" in a block's Options (⋮)
 * menu. Picking a field binds every attribute it fits in one step (a button
 * gets url, text and the new-tab target from a link field). The Attributes
 * panel stays the per-attribute way.
 */
import React, { useEffect, useRef, useState } from 'react';
import { BlockSettingsMenuControls } from '@wordpress/block-editor';
import { Button, MenuItem, Modal } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import {
    awaitingField,
    candidatesFor,
    CONNECTABLE_BLOCKS,
    connectedMetadata,
    withoutTawBindings,
    type Bindings,
    type Config,
    type FieldEntry,
} from './logic';

interface BlockInfo {
    name: string;
    metadata: { bindings?: Bindings; [key: string]: unknown };
}

function useBlock(clientId: string | null): BlockInfo | null {
    // Select the store's own block object (stable between renders), then shape it.
    const block = useSelect(
        (select) =>
            (clientId ? select('core/block-editor').getBlock(clientId) : null) as {
                name: string;
                attributes: Record<string, unknown>;
            } | null,
        [clientId],
    );
    return block ? { name: block.name, metadata: (block.attributes.metadata ?? {}) as BlockInfo['metadata'] } : null;
}

function MenuItems({
    config,
    clientId,
    onClose,
    onConnect,
}: {
    config: Config;
    clientId: string;
    onClose: () => void;
    onConnect: (clientId: string) => void;
}) {
    const block = useBlock(clientId);
    const { updateBlockAttributes } = useDispatch('core/block-editor');
    if (!block || !CONNECTABLE_BLOCKS.includes(block.name)) return null;

    const bound = Object.values(block.metadata.bindings ?? {}).some((binding) => binding?.source === config.source);

    return (
        <>
            <MenuItem
                onClick={() => {
                    onConnect(clientId);
                    onClose();
                }}
            >
                {__('Connect to TAW field…', 'taw-core')}
            </MenuItem>
            {bound && (
                <MenuItem
                    onClick={() => {
                        updateBlockAttributes(clientId, {
                            metadata: {
                                ...block.metadata,
                                bindings: withoutTawBindings(config.source, block.metadata.bindings),
                            },
                        });
                        onClose();
                    }}
                >
                    {__('Disconnect TAW field', 'taw-core')}
                </MenuItem>
            )}
        </>
    );
}

function ConnectModal({ config, clientId, onClose }: { config: Config; clientId: string; onClose: () => void }) {
    const block = useBlock(clientId);
    const postType = useSelect((select) => select('core/editor')?.getCurrentPostType?.() as string | undefined, []);
    const { updateBlockAttributes } = useDispatch('core/block-editor');
    if (!block) return null;

    const candidates = candidatesFor(config, block.name, { postType });
    const connect = (entry: FieldEntry) => {
        const next = connectedMetadata(config.source, block.name, block.metadata, entry);
        if (next) updateBlockAttributes(clientId, { metadata: next });
        onClose();
    };

    return (
        <Modal title={__('Connect to TAW field', 'taw-core')} onRequestClose={onClose} className="taw-bindings-connect">
            {candidates.length === 0 ? (
                <p>{__('No TAW field fits this block.', 'taw-core')}</p>
            ) : (
                <div role="list" style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 320 }}>
                    {candidates.map((entry) => (
                        <Button
                            key={JSON.stringify(entry.args)}
                            role="listitem"
                            variant="tertiary"
                            style={{ justifyContent: 'flex-start' }}
                            onClick={() => connect(entry)}
                        >
                            {entry.label}
                        </Button>
                    ))}
                </div>
            )}
        </Modal>
    );
}

/**
 * The block to prompt for a field: the selected one, when it has a
 * `taw/field` binding without a field (a "Field …" block just inserted).
 */
function useAwaiting(config: Config): string | null {
    const selected = useSelect(
        (select) => select('core/block-editor').getSelectedBlockClientId() as string | null,
        [],
    );
    const block = useBlock(selected);
    return selected && block && awaitingField(config.source, block.metadata.bindings) ? selected : null;
}

/** The plugin root: the menu fill, and the dialog (which outlives the closed menu). */
export function connectMenu(config: Config) {
    return function TawBindingsMenu() {
        const [connecting, setConnecting] = useState<string | null>(null);
        const awaiting = useAwaiting(config);
        const prompted = useRef(new Set<string>());

        // Open the picker once per fresh "Field …" block; after that, the toolbar or ⋮ menu.
        useEffect(() => {
            if (awaiting && !prompted.current.has(awaiting)) {
                prompted.current.add(awaiting);
                setConnecting(awaiting);
            }
        }, [awaiting]);

        return (
            <>
                <BlockSettingsMenuControls>
                    {({ selectedClientIds, onClose }: { selectedClientIds: string[]; onClose: () => void }) =>
                        selectedClientIds.length === 1 ? (
                            <MenuItems
                                config={config}
                                clientId={selectedClientIds[0]}
                                onClose={onClose}
                                onConnect={setConnecting}
                            />
                        ) : null
                    }
                </BlockSettingsMenuControls>
                {connecting && (
                    <ConnectModal config={config} clientId={connecting} onClose={() => setConnecting(null)} />
                )}
            </>
        );
    };
}

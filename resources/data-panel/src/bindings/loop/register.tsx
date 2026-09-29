/**
 * Registers the TAW Loop blocks' editors (ADR-0014). Their block.json lives in
 * taw-core (resources/blocks), registered on the server, which renders them;
 * the editor saves only the inner blocks.
 */
import React from 'react';
import { InnerBlocks } from '@wordpress/block-editor';
import { getBlockType, registerBlockType } from '@wordpress/blocks';
import type { Config } from '../logic';
import { LoopEdit } from './LoopEdit';
import { EmptyEdit, ItemEdit, PaginationEdit } from './parts';

const saveInner = () => <InnerBlocks.Content />;

export function registerLoop(config: Config): void {
    // Only where the server registered them (taw/core with the Loop, ADR-0014).
    if (!getBlockType || !config.loop) return;
    registerBlockType('taw/loop', { icon: 'update', edit: LoopEdit(config), save: saveInner });
    registerBlockType('taw/loop-item', { icon: 'excerpt-view', edit: ItemEdit(config), save: saveInner });
    registerBlockType('taw/loop-empty', { icon: 'dismiss', edit: EmptyEdit, save: saveInner });
    registerBlockType('taw/loop-pagination', { icon: 'controls-forward', edit: PaginationEdit, save: () => null });
}

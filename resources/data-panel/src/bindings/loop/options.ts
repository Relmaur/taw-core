/**
 * The values the popup, chip editor and condition builder offer for a block:
 * inside a TAW Loop item, its Row and Loop values first (ADR-0014).
 */
import { useMemo } from 'react';
import { useSelect } from '@wordpress/data';
import type { Config } from '../logic';
import { valueOptions, type ValueOption } from '../tags';
import { loopChain } from './chain';
import { loopOptions, type LoopAttributes } from './data';

export function useValueOptions(config: Config, clientId: string | null | undefined): ValueOption[] {
    const postType = useSelect((select) => select('core/editor')?.getCurrentPostType?.() as string | undefined, []);
    // A string, so equal chains are equal for useSelect.
    const chain = useSelect((select) => JSON.stringify(loopChain(clientId, select)), [clientId]);
    return useMemo(
        () => [
            ...loopOptions(config, JSON.parse(chain) as LoopAttributes[], postType),
            ...valueOptions(config, postType),
        ],
        [config, chain, postType],
    );
}

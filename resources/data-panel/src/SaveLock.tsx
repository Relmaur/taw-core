import { useEffect } from 'react';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import type { FieldsetDescriptor } from './types';
import { missingRequired } from './required';

export const LOCK = 'taw-data-panel';
export const NOTICE = 'taw-data-panel-required';

/**
 * Locks saving while a required data field is empty (the server would
 * refuse the save anyway), and says which ones in an editor notice with a
 * button that opens the panel. Renders nothing.
 */
export default function SaveLock({ fieldsets, sidebar }: { fieldsets: FieldsetDescriptor[]; sidebar: string }) {
    // Labels as one string, so the store only re-renders this when they change.
    const labels = useSelect(
        (select) => {
            const editor = select('core/editor');
            const meta = (editor.getEditedPostAttribute('meta') ?? {}) as Record<string, unknown>;
            return missingRequired(fieldsets, (binding) =>
                'meta' in binding ? meta[binding.meta] : editor.getEditedPostAttribute(binding.field),
            )
                .map((item) => item.label)
                .join(', ');
        },
        [fieldsets],
    );
    const { lockPostSaving, unlockPostSaving } = useDispatch('core/editor');
    const { createWarningNotice, removeNotice } = useDispatch('core/notices');
    const { enableComplementaryArea } = useDispatch('core/interface');

    useEffect(() => {
        if (labels === '') {
            unlockPostSaving(LOCK);
            removeNotice(NOTICE);
            return;
        }
        lockPostSaving(LOCK);
        createWarningNotice(
            sprintf(
                /* translators: %s: field labels. */ __('Fill in the required data before saving: %s.', 'taw-core'),
                labels,
            ),
            {
                id: NOTICE,
                isDismissible: false,
                actions: [
                    {
                        label: __('Open TAW Data', 'taw-core'),
                        onClick: () => enableComplementaryArea('core', sidebar),
                    },
                ],
            },
        );
        // The dispatchers are stable; re-run when the missing fields change.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [labels]);

    useEffect(
        () => () => {
            unlockPostSaving(LOCK);
            removeNotice(NOTICE);
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );

    return null;
}

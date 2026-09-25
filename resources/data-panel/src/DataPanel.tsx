import React, { useMemo } from 'react';
import { Notice } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';
import type { PanelDescriptor } from './types';
import { isVisible } from './templates';
import { missingRequired } from './required';
import type { Missing } from './required';
import { SaveErrors } from './context';
import { useSaveErrors } from './useSaveErrors';
import Fieldset from './Fieldset';
import SaveLock from './SaveLock';

export const SIDEBAR = 'taw-data-panel';
export const ICON = 'database';

/**
 * The "TAW Data" sidebar (ADR-0007 § 7): its own icon in the editor header
 * (open, close, pin) and an entry in the ⋮ menu. Renders nothing when the
 * post has no panel fieldsets. The save lock works with the sidebar closed.
 */
export default function DataPanel() {
    const { panel, editedTemplate, savedTemplate } = useSelect((select) => {
        const editor = select('core/editor');
        return {
            panel: editor.getEditorSettings()?.tawDataPanel as PanelDescriptor | undefined,
            editedTemplate: (editor.getEditedPostAttribute('template') ?? '') as string,
            savedTemplate: (editor.getCurrentPostAttribute('template') ?? '') as string,
        };
    }, []);

    const visible = useMemo(
        () => (panel?.fieldsets ?? []).filter((fieldset) => isVisible(fieldset, editedTemplate, savedTemplate)),
        [panel, editedTemplate, savedTemplate],
    );

    if (!panel || panel.fieldsets.length === 0) {
        return null;
    }

    const title = __('TAW Data', 'taw-core');

    return (
        <>
            <SaveLock fieldsets={visible} sidebar={`${SIDEBAR}/${SIDEBAR}`} />
            <PluginSidebarMoreMenuItem target={SIDEBAR} icon={ICON}>
                {title}
            </PluginSidebarMoreMenuItem>
            <PluginSidebar name={SIDEBAR} title={title} icon={ICON}>
                <Content panel={panel} visible={visible} />
            </PluginSidebar>
        </>
    );
}

function Content({ panel, visible }: { panel: PanelDescriptor; visible: PanelDescriptor['fieldsets'] }) {
    const saveErrors = useSaveErrors();
    // Once editing has started, empty required fields are marked too (saving is locked until they're filled).
    const missingKey = useSelect(
        (select) => {
            const editor = select('core/editor');
            if (!editor.isEditedPostDirty?.()) return '[]';
            const meta = (editor.getEditedPostAttribute('meta') ?? {}) as Record<string, unknown>;
            // A string, so the store only re-renders this when the list changes.
            return JSON.stringify(
                missingRequired(visible, (binding) =>
                    'meta' in binding ? meta[binding.meta] : editor.getEditedPostAttribute(binding.field),
                ),
            );
        },
        [visible],
    );
    const errors = useMemo(() => {
        const marks: Record<string, string> = {};
        for (const item of JSON.parse(missingKey) as Missing[]) {
            marks[item.key] = sprintf(
                /* translators: %s: field label. */ __('%s is required.', 'taw-core'),
                item.label,
            );
        }
        return { ...marks, ...saveErrors };
    }, [missingKey, saveErrors]);

    return (
        <SaveErrors.Provider value={errors}>
            <div className="taw-data-panel">
                {panel.warnings.map((warning) => (
                    <Notice key={warning} status="warning" isDismissible={false}>
                        {warning}
                    </Notice>
                ))}
                {visible.length === 0 ? (
                    <p className="taw-data-panel__empty">{__('No data fields apply to this template.', 'taw-core')}</p>
                ) : (
                    visible.map((fieldset, index) => (
                        <Fieldset key={fieldset.id} fieldset={fieldset} initialOpen={index === 0} />
                    ))
                )}
            </div>
        </SaveErrors.Provider>
    );
}

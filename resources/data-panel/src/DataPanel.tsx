import React from 'react';
import { Notice } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import type { PanelDescriptor } from './types';
import { isVisible } from './templates';
import Fieldset from './Fieldset';

export const SIDEBAR = 'taw-data-panel';
export const ICON = 'database';

/**
 * The "TAW Data" sidebar (ADR-0007 § 7): its own icon in the editor header
 * (open, close, pin) and an entry in the ⋮ menu. Renders nothing when the
 * post has no panel fieldsets.
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

    if (!panel || panel.fieldsets.length === 0) {
        return null;
    }

    const visible = panel.fieldsets.filter((fieldset) => isVisible(fieldset, editedTemplate, savedTemplate));
    const title = __('TAW Data', 'taw-core');

    return (
        <>
            <PluginSidebarMoreMenuItem target={SIDEBAR} icon={ICON}>
                {title}
            </PluginSidebarMoreMenuItem>
            <PluginSidebar name={SIDEBAR} title={title} icon={ICON}>
                <div className="taw-data-panel">
                    {panel.warnings.map((warning) => (
                        <Notice key={warning} status="warning" isDismissible={false}>
                            {warning}
                        </Notice>
                    ))}
                    {visible.length === 0 ? (
                        <p className="taw-data-panel__empty">
                            {__('No data fields apply to this template.', 'taw-core')}
                        </p>
                    ) : (
                        visible.map((fieldset, index) => (
                            <Fieldset key={fieldset.id} fieldset={fieldset} initialOpen={index === 0} />
                        ))
                    )}
                </div>
            </PluginSidebar>
        </>
    );
}

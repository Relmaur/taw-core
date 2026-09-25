import React from 'react';
import { Dashicon, PanelBody, TabPanel } from '@wordpress/components';
import type { FieldDescriptor, FieldsetDescriptor } from './types';
import { conditionsMet } from './conditions';
import { ConditionValues } from './context';
import { useFieldValues } from './useValues';
import FieldControl from './FieldControl';

/** A dashicons class ("dashicons-book") as PanelBody's icon; anything else (SVG, URL) is left out. */
function dashicon(icon: string): React.JSX.Element | undefined {
    if (!icon.startsWith('dashicons-')) return undefined;
    return <Dashicon icon={icon.slice('dashicons-'.length) as React.ComponentProps<typeof Dashicon>['icon']} />;
}

/** One column of fields with the panel's spacing (PanelBody has no content wrapper of its own). */
function Fields({ fields, values }: { fields: FieldDescriptor[]; values: Record<string, unknown> }) {
    const shown = fields.filter((field) => conditionsMet(field.conditions, values));
    if (shown.length === 0) return null;
    return (
        <div className="taw-data-panel__fields">
            {shown.map((field) => (
                <FieldControl key={field.id} field={field} />
            ))}
        </div>
    );
}

/**
 * One fieldset: its fields, in tabs when it defines them. Fields no tab
 * claims stay visible above the tabs (the metabox shows them too).
 */
export default function Fieldset({ fieldset, initialOpen }: { fieldset: FieldsetDescriptor; initialOpen: boolean }) {
    const values = useFieldValues(fieldset.fields);
    const tabs = fieldset.tabs.filter((tab) => tab.fields.length > 0);
    const claimed = new Set(tabs.flatMap((tab) => tab.fields));
    const loose = fieldset.fields.filter((field) => !claimed.has(field.id));

    return (
        <ConditionValues.Provider value={values}>
            <PanelBody
                title={fieldset.title}
                icon={dashicon(fieldset.icon)}
                initialOpen={initialOpen}
                className="taw-data-panel__fieldset"
            >
                <Fields fields={tabs.length > 0 ? loose : fieldset.fields} values={values} />
                {tabs.length > 0 ? (
                    <TabPanel
                        className="taw-data-panel__tabs"
                        tabs={tabs.map((tab, index) => ({ name: `tab-${index}`, title: tab.label || `${index + 1}` }))}
                    >
                        {(tab: { name: string }) => {
                            const index = Number(tab.name.slice(4));
                            const ids = tabs[index].fields;
                            return (
                                <Fields
                                    fields={fieldset.fields.filter((field) => ids.includes(field.id))}
                                    values={values}
                                />
                            );
                        }}
                    </TabPanel>
                ) : null}
            </PanelBody>
        </ConditionValues.Provider>
    );
}

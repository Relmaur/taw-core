import React from 'react';
import { sprintf, __ } from '@wordpress/i18n';
import type { ControlProps, FieldDescriptor } from './types';
import { useBinding } from './useValues';
import { Checkbox, Range, Select } from './controls/ChoiceControls';
import { Color, DateField } from './controls/PickerControls';
import { NumberInput, Text, Textarea } from './controls/TextControls';

/** Field type → control. Media, rich and structured types arrive in later steps (plan P3/P4). */
export const CONTROLS: Record<string, React.ComponentType<ControlProps>> = {
    text: Text,
    url: Text,
    textarea: Textarea,
    number: NumberInput,
    range: Range,
    select: Select,
    checkbox: Checkbox,
    color: Color,
    datepicker: DateField,
};

export default function FieldControl({ field }: { field: FieldDescriptor }) {
    const [value, setValue] = useBinding(field.binding);
    const Control = CONTROLS[field.type];

    if (!Control) {
        return (
            <p className="taw-data-panel__pending" role="note">
                {sprintf(
                    /* translators: 1: field label, 2: field type. */
                    __('%1$s (%2$s) can’t be edited in the panel yet.', 'taw-core'),
                    field.label ?? field.id,
                    field.type,
                )}
            </p>
        );
    }

    return (
        <div className={`taw-data-panel__field taw-data-panel__field--${field.type}`} data-field={field.id}>
            <Control field={field} value={value} onChange={setValue} />
        </div>
    );
}

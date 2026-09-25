import React, { useContext } from 'react';
import { sprintf, __ } from '@wordpress/i18n';
import type { ControlProps, FieldDescriptor } from './types';
import { SaveErrors } from './context';
import { bindingKey } from './required';
import { useBinding } from './useValues';
import { Group } from './controls/Group';
import { Repeater } from './controls/Repeater';
import { Checkbox, Range, Select } from './controls/ChoiceControls';
import { GradientText, HubspotForm, Link } from './controls/CompositeControls';
import { IconField } from './controls/IconControl';
import { Files, Image } from './controls/MediaControls';
import { Color, DateField } from './controls/PickerControls';
import { PostSelect } from './controls/PostSelect';
import { Wysiwyg } from './controls/RichContent';
import { NumberInput, Text, Textarea } from './controls/TextControls';

/** Field type → control. group isn't here: it has no value of its own (FieldControl renders it). */
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
    image: Image,
    files: Files,
    icon: IconField,
    post_select: PostSelect,
    wysiwyg: Wysiwyg,
    gradient_text: GradientText,
    hubspot_form: HubspotForm,
    link: Link,
    repeater: Repeater,
};

/** The control for a field's type, on any value (bound or a repeater row's). */
export function ControlFor({ field, value, onChange }: ControlProps) {
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

    return <Control field={field} value={value} onChange={onChange} />;
}

/** The last failed save's message for this field, when there is one. */
function FieldError({ message }: { message: string }) {
    return (
        <p className="taw-data-panel__error" role="alert">
            {message}
        </p>
    );
}

/** A field bound to the post (group: its sub-fields are). */
export default function FieldControl({ field }: { field: FieldDescriptor }) {
    const errors = useContext(SaveErrors);
    const error = errors[bindingKey(field.binding)];
    const className = `taw-data-panel__field taw-data-panel__field--${field.type}${error ? ' has-error' : ''}`;

    if (field.type === 'group') {
        return (
            <div className={className} data-field={field.id}>
                <Group field={field} />
            </div>
        );
    }

    return (
        <div className={className} data-field={field.id}>
            <BoundControl field={field} />
            {error ? <FieldError message={error} /> : null}
        </div>
    );
}

function BoundControl({ field }: { field: FieldDescriptor }) {
    const [value, setValue] = useBinding(field.binding);
    return <ControlFor field={field} value={value} onChange={setValue} />;
}

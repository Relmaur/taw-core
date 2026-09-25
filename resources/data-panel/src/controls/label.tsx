import React from 'react';
import type { FieldDescriptor } from '../types';

/** The label as plain text (for controls that only take a string). */
export function fieldLabelText(field: FieldDescriptor): string {
    return `${field.label ?? field.id}${field.required ? ' *' : ''}`;
}

/** The field's label, with a marker when it's required. */
export function fieldLabel(field: FieldDescriptor): React.ReactNode {
    const text = field.label ?? field.id;
    return field.required ? (
        <>
            {text}{' '}
            <span className="taw-data-panel__required" aria-hidden="true">
                *
            </span>
        </>
    ) : (
        text
    );
}

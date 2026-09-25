import React from 'react';
import type { FieldDescriptor } from '../types';
import { fieldLabel } from './label';

/**
 * A field made of several parts (gradient_text, hubspot_form; group and
 * repeater next): one framed card, the field's label in its header, so its
 * parts read as one thing and apart from the fields around it.
 */
export default function FieldGroup({
    field,
    actions,
    children,
}: {
    field: FieldDescriptor;
    actions?: React.ReactNode;
    children: React.ReactNode;
}) {
    const titleId = `taw-data-${field.id}-title`;

    return (
        <div className="taw-data-panel__group" role="group" aria-labelledby={titleId}>
            <div className="taw-data-panel__group-header">
                <span id={titleId} className="taw-data-panel__group-title">
                    {fieldLabel(field)}
                </span>
                {actions}
            </div>
            <div className="taw-data-panel__group-body">{children}</div>
            {field.description ? <p className="taw-data-panel__group-help">{field.description}</p> : null}
        </div>
    );
}

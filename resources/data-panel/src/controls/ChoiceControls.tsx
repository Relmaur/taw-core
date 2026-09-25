import React from 'react';
import { RangeControl, SelectControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ControlProps, FieldDescriptor } from '../types';
import { fieldLabel, fieldLabelText } from './label';

/** A field's options as the metabox reads them: value => label (a JSON list uses its indexes). */
export function selectOptions(field: FieldDescriptor): Array<{ value: string; label: string }> {
    const options = field.options ?? {};
    return Object.entries(options).map(([value, label]) => ({ value, label: String(label) }));
}

export function Select({ field, value, onChange }: ControlProps) {
    const options = selectOptions(field);
    const current = value === null || value === undefined ? '' : String(value);
    // Like the metabox's <select>, an unset value shows the first option;
    // offer an explicit empty choice so it isn't silently "chosen".
    const withEmpty =
        current === '' && !options.some((option) => option.value === '')
            ? [{ value: '', label: __('— Select —', 'taw-core') }, ...options]
            : options;

    return (
        <SelectControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
            label={fieldLabel(field)}
            help={field.description}
            value={current}
            options={withEmpty}
            disabled={field.readonly}
            onChange={onChange}
        />
    );
}

/** checkbox: stored as '1'/'0', read over REST as a boolean. */
export function Checkbox({ field, value, onChange }: ControlProps) {
    const checked = value === true || value === '1' || value === 1;
    return (
        <ToggleControl
            __nextHasNoMarginBottom
            label={fieldLabelText(field)}
            help={field.description}
            checked={checked}
            disabled={field.readonly}
            onChange={(next: boolean) => onChange(next)}
        />
    );
}

export function Range({ field, value, onChange }: ControlProps) {
    const min = field.min ?? 0;
    const max = field.max ?? 100;
    const fallback = typeof field.default === 'number' ? field.default : min;
    const current = value === '' || value === null || value === undefined ? fallback : Number(value);

    return (
        <RangeControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
            label={field.unit ? `${fieldLabelText(field)} (${field.unit})` : fieldLabelText(field)}
            help={field.description}
            min={min}
            max={max}
            step={field.step ?? 1}
            value={current}
            disabled={field.readonly}
            onChange={(next?: number) => onChange(next ?? fallback)}
        />
    );
}

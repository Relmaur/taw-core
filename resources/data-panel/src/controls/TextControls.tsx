import React from 'react';
import { TextareaControl, TextControl } from '@wordpress/components';
import type { ControlProps } from '../types';
import { fieldLabel } from './label';

const asText = (value: unknown): string => (value === null || value === undefined ? '' : String(value));

/** text and url. */
export function Text({ field, value, onChange }: ControlProps) {
    return (
        <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
            type={field.type === 'url' ? 'url' : 'text'}
            label={fieldLabel(field)}
            help={field.description}
            placeholder={field.placeholder}
            value={asText(value)}
            disabled={field.readonly}
            required={field.required}
            onChange={onChange}
        />
    );
}

export function Textarea({ field, value, onChange }: ControlProps) {
    return (
        <TextareaControl
            __nextHasNoMarginBottom
            label={fieldLabel(field)}
            help={field.description}
            placeholder={field.placeholder}
            rows={field.rows ?? 4}
            value={asText(value)}
            disabled={field.readonly}
            onChange={onChange}
        />
    );
}

/** number: an empty box stays empty; anything else is sent as a number. */
export function NumberInput({ field, value, onChange }: ControlProps) {
    return (
        <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
            type="number"
            label={fieldLabel(field)}
            help={field.description}
            placeholder={field.placeholder}
            min={field.min}
            max={field.max}
            step={field.step ?? 'any'}
            value={asText(value)}
            disabled={field.readonly}
            onChange={(next: string) => onChange(next === '' ? '' : Number(next))}
        />
    );
}

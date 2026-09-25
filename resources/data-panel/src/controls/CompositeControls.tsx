import React from 'react';
import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import type { ControlProps } from '../types';
import FieldGroup from './FieldGroup';

/**
 * gradient_text and hubspot_form are stored as JSON strings in one meta key
 * (Metabox::sanitizeGradientTextValue / sanitizeHubspotFormValue), so the
 * panel reads and writes the same strings. link is a structured type: it's
 * edited as the decoded `taw_<id>` object (a JSON string inside repeater rows).
 */

export interface Segment {
    text: string;
    highlighted: boolean;
}

function parseJson(value: unknown): unknown {
    if (typeof value !== 'string') return value;
    try {
        return JSON.parse(value);
    } catch {
        return null;
    }
}

export function segments(value: unknown): Segment[] {
    const list = parseJson(value);
    if (!Array.isArray(list)) return [];
    return list
        .filter((item): item is Record<string, unknown> => typeof item === 'object' && item !== null)
        .map((item) => ({
            text: typeof item.text === 'string' ? item.text : '',
            highlighted: item.highlighted === true || item.highlighted === 1 || item.highlighted === '1',
        }));
}

/** gradient_text: ordered text segments, some highlighted. Segment text keeps its spaces. */
export function GradientText({ field, value, onChange }: ControlProps) {
    const list = segments(value);
    const write = (next: Segment[]) => onChange(JSON.stringify(next));
    const update = (index: number, patch: Partial<Segment>) =>
        write(list.map((segment, i) => (i === index ? { ...segment, ...patch } : segment)));
    const id = `taw-data-${field.id}`;

    return (
        <FieldGroup field={field}>
            {list.length > 0 ? (
                <p className="taw-data-panel__gradient-preview" aria-hidden="true">
                    {list.map((segment, index) => (
                        <span key={index} className={segment.highlighted ? 'is-highlighted' : undefined}>
                            {segment.text}
                        </span>
                    ))}
                </p>
            ) : (
                <p className="taw-data-panel__group-empty">{__('No segments yet.', 'taw-core')}</p>
            )}
            {list.length > 0 ? (
                <ol className="taw-data-panel__segments">
                    {list.map((segment, index) => (
                        <li key={index} className="taw-data-panel__segment">
                            <span className="taw-data-panel__segment-number" aria-hidden="true">
                                {index + 1}
                            </span>
                            <div className="taw-data-panel__segment-fields">
                                <TextControl
                                    __next40pxDefaultSize
                                    __nextHasNoMarginBottom
                                    label={sprintf(
                                        /* translators: %d: segment number. */ __('Segment %d', 'taw-core'),
                                        index + 1,
                                    )}
                                    hideLabelFromVision
                                    placeholder={__('Segment text', 'taw-core')}
                                    value={segment.text}
                                    disabled={field.readonly}
                                    onChange={(text: string) => update(index, { text })}
                                />
                                <div className="taw-data-panel__segment-row">
                                    <ToggleControl
                                        __nextHasNoMarginBottom
                                        label={__('Highlighted', 'taw-core')}
                                        checked={segment.highlighted}
                                        disabled={field.readonly}
                                        onChange={(highlighted: boolean) => update(index, { highlighted })}
                                    />
                                    {field.readonly ? null : (
                                        <Button
                                            size="small"
                                            icon="trash"
                                            className="taw-data-panel__remove"
                                            label={sprintf(
                                                /* translators: %d: segment number. */ __(
                                                    'Remove segment %d',
                                                    'taw-core',
                                                ),
                                                index + 1,
                                            )}
                                            onClick={() => write(list.filter((_, i) => i !== index))}
                                        />
                                    )}
                                </div>
                            </div>
                        </li>
                    ))}
                </ol>
            ) : null}
            {field.readonly ? null : (
                <Button
                    id={id}
                    className="taw-data-panel__add"
                    variant="secondary"
                    icon="plus-alt2"
                    onClick={() => write([...list, { text: '', highlighted: false }])}
                    __next40pxDefaultSize
                >
                    {__('Add segment', 'taw-core')}
                </Button>
            )}
        </FieldGroup>
    );
}

export interface HubspotConfig {
    portal_id: string;
    form_id: string;
    region: string;
}

export function hubspotConfig(value: unknown): HubspotConfig {
    const raw = parseJson(value);
    const config = typeof raw === 'object' && raw !== null ? (raw as Record<string, unknown>) : {};
    const text = (key: string) => (config[key] === undefined || config[key] === null ? '' : String(config[key]));
    return { portal_id: text('portal_id'), form_id: text('form_id'), region: text('region') || 'na1' };
}

/** hubspot_form: portal ID, form ID and region (default na1). */
export function HubspotForm({ field, value, onChange }: ControlProps) {
    const config = hubspotConfig(value);
    const set = (key: keyof HubspotConfig) => (next: string) => onChange(JSON.stringify({ ...config, [key]: next }));

    return (
        <FieldGroup field={field}>
            <TextControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                label={__('Portal ID', 'taw-core')}
                value={config.portal_id}
                disabled={field.readonly}
                onChange={set('portal_id')}
            />
            <TextControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                label={__('Form ID', 'taw-core')}
                value={config.form_id}
                disabled={field.readonly}
                onChange={set('form_id')}
            />
            <TextControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                label={__('Region', 'taw-core')}
                placeholder="na1"
                value={config.region}
                disabled={field.readonly}
                onChange={set('region')}
            />
        </FieldGroup>
    );
}

export interface LinkValue {
    url: string;
    label: string;
    new_tab: boolean;
}

/** A link from the REST `taw_<id>` object, a repeater row's JSON string, or nothing. */
export function linkValue(value: unknown): LinkValue {
    const raw = parseJson(value);
    const link = typeof raw === 'object' && raw !== null ? (raw as Record<string, unknown>) : {};
    const text = (key: string) => (typeof link[key] === 'string' ? (link[key] as string) : '');
    return {
        url: text('url'),
        label: text('label'),
        new_tab: link.new_tab === true || link.new_tab === 1 || link.new_tab === '1' || link.new_tab === 'true',
    };
}

/** link: URL, text and "open in a new tab". The server stores nothing without a URL. */
export function Link({ field, value, onChange }: ControlProps) {
    const link = linkValue(value);
    const set = (patch: Partial<LinkValue>) => onChange({ ...link, ...patch });

    return (
        <FieldGroup field={field}>
            <TextControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                inputMode="url"
                label={__('URL', 'taw-core')}
                placeholder={__('https:// or /path', 'taw-core')}
                value={link.url}
                disabled={field.readonly}
                onChange={(url: string) => set({ url })}
            />
            <TextControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                label={__('Link text', 'taw-core')}
                value={link.label}
                disabled={field.readonly}
                onChange={(label: string) => set({ label })}
            />
            <ToggleControl
                __nextHasNoMarginBottom
                label={__('Open in a new tab', 'taw-core')}
                checked={link.new_tab}
                disabled={field.readonly}
                onChange={(new_tab: boolean) => set({ new_tab })}
            />
        </FieldGroup>
    );
}

import React from 'react';
import {
    BaseControl,
    Button,
    ColorIndicator,
    ColorPalette,
    DatePicker,
    Dashicon,
    Dropdown,
    TextControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import type { ControlProps, FieldDescriptor } from '../types';
import { formatDate, isSupportedFormat, parseDate } from '../dates';
import { fieldLabel } from './label';

interface PickerProps {
    field: FieldDescriptor;
    id: string;
    /** What the row shows: the value, or a placeholder when empty. */
    display: string;
    empty: boolean;
    leading: React.ReactNode;
    children: (onClose: () => void) => React.ReactNode;
}

/**
 * A full-width row that opens a popover, the way core's own color and date
 * settings look in the sidebar.
 */
function PickerRow({ field, id, display, empty, leading, children }: PickerProps) {
    return (
        <BaseControl __nextHasNoMarginBottom id={id} label={fieldLabel(field)} help={field.description}>
            <Dropdown
                className="taw-data-panel__picker"
                contentClassName="taw-data-panel__picker-popover"
                popoverProps={{ placement: 'left-start', offset: 36, shift: true }}
                renderToggle={({ isOpen, onToggle }) => (
                    <Button
                        id={id}
                        className={`taw-data-panel__picker-toggle${isOpen ? ' is-open' : ''}${empty ? ' is-empty' : ''}`}
                        aria-expanded={isOpen}
                        onClick={onToggle}
                        disabled={field.readonly}
                        __next40pxDefaultSize
                    >
                        {leading}
                        <span className="taw-data-panel__picker-value">{display}</span>
                    </Button>
                )}
                renderContent={({ onClose }) => children(onClose)}
            />
        </BaseControl>
    );
}

interface PaletteColor {
    name: string;
    slug?: string;
    color: string;
}

/** color: a hex value (sanitize_hex_color), picked from the theme's palette or freely. */
export function Color({ field, value, onChange }: ControlProps) {
    const color = typeof value === 'string' ? value : '';
    const palette = useSelect(
        (select) =>
            ((select('core/block-editor')?.getSettings?.()?.colors ?? []) as PaletteColor[]).filter((c) =>
                /^#/.test(c.color),
            ),
        [],
    );
    const named = palette.find((c) => c.color.toLowerCase() === color.toLowerCase());

    return (
        <PickerRow
            field={field}
            id={`taw-data-${field.id}`}
            display={color ? (named ? `${named.name} (${color})` : color) : __('Choose a color', 'taw-core')}
            empty={!color}
            leading={<ColorIndicator colorValue={color || undefined} className={color ? '' : 'is-empty'} />}
        >
            {() => (
                <div className="taw-data-panel__popover-body">
                    <ColorPalette
                        colors={palette}
                        value={color || undefined}
                        enableAlpha={false}
                        clearable
                        onChange={(next?: string) => onChange(next ?? '')}
                    />
                </div>
            )}
        </PickerRow>
    );
}

/**
 * datepicker: stored in the field's jQuery UI format (default yy-mm-dd). A
 * format the panel can't read or write falls back to a text box.
 */
export function DateField({ field, value, onChange }: ControlProps) {
    const format = field.date_format ?? 'yy-mm-dd';
    const text = typeof value === 'string' ? value : '';

    if (!isSupportedFormat(format)) {
        return (
            <TextControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                label={fieldLabel(field)}
                help={field.description}
                placeholder={field.placeholder ?? format}
                value={text}
                disabled={field.readonly}
                onChange={onChange}
            />
        );
    }

    const date = text ? parseDate(text, format) : null;
    const display = date
        ? date.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
        : text || __('Choose a date', 'taw-core');

    return (
        <PickerRow
            field={field}
            id={`taw-data-${field.id}`}
            display={display}
            empty={!text}
            leading={<Dashicon icon="calendar-alt" className="taw-data-panel__picker-icon" />}
        >
            {(onClose) => (
                <div className="taw-data-panel__popover-body">
                    <DatePicker
                        currentDate={date ?? undefined}
                        onChange={(iso: string) => {
                            onChange(formatDate(new Date(iso), format));
                            onClose();
                        }}
                    />
                    <div className="taw-data-panel__popover-footer">
                        <Button
                            variant="tertiary"
                            size="small"
                            disabled={!text}
                            onClick={() => {
                                onChange('');
                                onClose();
                            }}
                        >
                            {__('Clear', 'taw-core')}
                        </Button>
                    </div>
                </div>
            )}
        </PickerRow>
    );
}

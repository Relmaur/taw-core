import React, { useEffect, useState } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { BaseControl, Button, Dropdown, SearchControl, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import type { ControlProps, PanelDescriptor } from '../types';
import { useDebouncedFetch } from '../useDebouncedFetch';
import { fieldLabel } from './label';

export interface IconResult {
    name: string;
    /** Inline SVG rendered by taw/core from its bundled Lucide set. */
    svg: string;
}

/** Search the Lucide set (GET taw/v1/icons, TAW\Core\Rest\IconsEndpoint). */
export function searchIcons(search: string, perPage = 60): Promise<IconResult[]> {
    return apiFetch<IconResult[]>({
        path: `/taw/v1/icons?search=${encodeURIComponent(search)}&per_page=${perPage}`,
    });
}

/** The stored icon's SVG (the endpoint matches names, so search for it and pick the exact one). */
function useIconSvg(name: string): string {
    const [svg, setSvg] = useState('');
    useEffect(() => {
        if (!name) return undefined;
        let live = true;
        searchIcons(name, 120)
            .then((results) => live && setSvg(results.find((icon) => icon.name === name)?.svg ?? ''))
            .catch(() => live && setSvg(''));
        return () => {
            live = false;
        };
    }, [name]);
    return name ? svg : '';
}

function Svg({ markup }: { markup: string }) {
    // Server-rendered from taw/core's own bundled icon files, not user input.
    return (
        <span className="taw-data-panel__icon-svg" aria-hidden="true" dangerouslySetInnerHTML={{ __html: markup }} />
    );
}

function IconGrid({ value, onPick }: { value: string; onPick: (name: string) => void }) {
    const [query, setQuery] = useState('');
    const { data, loading, error } = useDebouncedFetch(query, () => searchIcons(query));
    const results = data ?? [];

    return (
        <div className="taw-data-panel__popover-body taw-data-panel__icons">
            <SearchControl
                __nextHasNoMarginBottom
                label={__('Search icons', 'taw-core')}
                value={query}
                onChange={setQuery}
            />
            {error ? (
                <p className="taw-data-panel__icons-note">{__('Icons couldn’t be loaded.', 'taw-core')}</p>
            ) : loading && results.length === 0 ? (
                <Spinner />
            ) : results.length === 0 ? (
                <p className="taw-data-panel__icons-note">{__('No icons found.', 'taw-core')}</p>
            ) : (
                <div className="taw-data-panel__icon-grid" role="listbox" aria-label={__('Icons', 'taw-core')}>
                    {results.map((icon) => (
                        <Button
                            key={icon.name}
                            role="option"
                            aria-selected={icon.name === value}
                            className={`taw-data-panel__icon-option${icon.name === value ? ' is-selected' : ''}`}
                            label={icon.name}
                            showTooltip
                            onClick={() => onPick(icon.name)}
                        >
                            <Svg markup={icon.svg} />
                        </Button>
                    ))}
                </div>
            )}
        </div>
    );
}

/** icon: a Lucide icon name (sanitize_key), like the metabox. */
export function IconField({ field, value, onChange }: ControlProps) {
    const name = typeof value === 'string' ? value : '';
    const svg = useIconSvg(name);
    const enabled = useSelect(
        (select) =>
            Boolean((select('core/editor').getEditorSettings()?.tawDataPanel as PanelDescriptor | undefined)?.icons),
        [],
    );
    const id = `taw-data-${field.id}`;

    if (!enabled) {
        return (
            <BaseControl __nextHasNoMarginBottom id={id} label={fieldLabel(field)}>
                <p className="taw-data-panel__pending">
                    {__('Icons aren’t switched on for this site (Lucide::enable()).', 'taw-core')}
                </p>
            </BaseControl>
        );
    }

    return (
        <BaseControl __nextHasNoMarginBottom id={id} label={fieldLabel(field)} help={field.description}>
            <Dropdown
                className="taw-data-panel__picker"
                contentClassName="taw-data-panel__picker-popover taw-data-panel__icons-popover"
                popoverProps={{ placement: 'left-start', offset: 36, shift: true }}
                renderToggle={({ isOpen, onToggle }) => (
                    <Button
                        id={id}
                        className={`taw-data-panel__picker-toggle${isOpen ? ' is-open' : ''}${name ? '' : ' is-empty'}`}
                        aria-expanded={isOpen}
                        onClick={onToggle}
                        disabled={field.readonly}
                        __next40pxDefaultSize
                    >
                        {name ? <Svg markup={svg} /> : null}
                        <span className="taw-data-panel__picker-value">{name || __('Choose an icon', 'taw-core')}</span>
                    </Button>
                )}
                renderContent={({ onClose }) => (
                    <>
                        <IconGrid
                            value={name}
                            onPick={(next) => {
                                onChange(next);
                                onClose();
                            }}
                        />
                        <div className="taw-data-panel__popover-footer taw-data-panel__popover-footer--padded">
                            <Button
                                variant="tertiary"
                                size="small"
                                disabled={!name}
                                onClick={() => {
                                    onChange('');
                                    onClose();
                                }}
                            >
                                {name
                                    ? sprintf(/* translators: %s: icon name. */ __('Remove %s', 'taw-core'), name)
                                    : __('Remove', 'taw-core')}
                            </Button>
                        </div>
                    </>
                )}
            />
        </BaseControl>
    );
}

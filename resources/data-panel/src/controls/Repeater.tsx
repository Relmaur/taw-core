import React, { useContext, useState } from 'react';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { ConditionValues } from '../context';
import { conditionsMet } from '../conditions';
import { asRows, move, newRow, rowKey, rowSummary, syncKeys } from '../repeater';
import type { Row } from '../repeater';
import type { ControlProps, FieldDescriptor } from '../types';
import { ControlFor } from '../FieldControl';
import FieldGroup from './FieldGroup';

interface RowProps {
    field: FieldDescriptor;
    subs: FieldDescriptor[];
    row: Row;
    rowId: string;
    index: number;
    count: number;
    open: boolean;
    canRemove: boolean;
    onToggle: () => void;
    onMove: (from: number, to: number) => void;
    onRemove: () => void;
    onChange: (id: string, value: unknown) => void;
}

function RepeaterRow({
    field,
    subs,
    row,
    rowId,
    index,
    count,
    open,
    canRemove,
    onToggle,
    onMove,
    onRemove,
    onChange,
}: RowProps) {
    const outer = useContext(ConditionValues);
    // Siblings in the row first, then the fieldset's own fields (as the metabox resolves them).
    const values = { ...outer, ...row };
    const summary = rowSummary(row, subs);
    const title = summary || sprintf(/* translators: %d: item number. */ __('Item %d', 'taw-core'), index + 1);
    const bodyId = `taw-data-${field.id}-${rowId}`;

    return (
        <li className={`taw-data-panel__row${open ? ' is-open' : ''}`}>
            <div className="taw-data-panel__row-header">
                <Button
                    className="taw-data-panel__row-toggle"
                    aria-expanded={open}
                    aria-controls={bodyId}
                    icon={open ? 'arrow-down-alt2' : 'arrow-right-alt2'}
                    onClick={onToggle}
                >
                    <span className="taw-data-panel__row-number" aria-hidden="true">
                        {index + 1}
                    </span>
                    <span className="taw-data-panel__row-title">{title}</span>
                </Button>
                {field.readonly ? null : (
                    <span className="taw-data-panel__file-actions">
                        <Button
                            size="small"
                            icon="arrow-up-alt2"
                            label={sprintf(/* translators: %s: item title. */ __('Move %s up', 'taw-core'), title)}
                            disabled={index === 0}
                            onClick={() => onMove(index, index - 1)}
                        />
                        <Button
                            size="small"
                            icon="arrow-down-alt2"
                            label={sprintf(/* translators: %s: item title. */ __('Move %s down', 'taw-core'), title)}
                            disabled={index === count - 1}
                            onClick={() => onMove(index, index + 1)}
                        />
                        <Button
                            size="small"
                            icon="trash"
                            className="taw-data-panel__remove"
                            label={sprintf(/* translators: %s: item title. */ __('Remove %s', 'taw-core'), title)}
                            disabled={!canRemove}
                            onClick={onRemove}
                        />
                    </span>
                )}
            </div>
            {open ? (
                <div className="taw-data-panel__row-body" id={bodyId}>
                    {subs
                        .filter((sub) => conditionsMet(sub.conditions, values))
                        .map((sub) => (
                            <div
                                key={sub.id}
                                className={`taw-data-panel__field taw-data-panel__field--${sub.type}`}
                                data-field={sub.id}
                            >
                                <ControlFor
                                    // A unique id per row, so labels point at the right input.
                                    field={{
                                        ...sub,
                                        id: `${field.id}-${rowId}-${sub.id}`,
                                        label: sub.label ?? sub.id,
                                        readonly: field.readonly || sub.readonly,
                                    }}
                                    value={row[sub.id]}
                                    onChange={(next) => onChange(sub.id, next)}
                                />
                            </div>
                        ))}
                </div>
            ) : null}
        </li>
    );
}

/**
 * repeater: rows of sub-fields (the decoded `taw_<id>` REST field). Add,
 * remove, reorder and collapse; `min`/`max` and read-only as in the metabox.
 * Rows are stacked cards; the metabox's tabbed layouts aren't used here.
 */
export function Repeater({ field, value, onChange }: ControlProps) {
    const rows = asRows(value);
    const subs = field.fields ?? [];
    const max = field.max && field.max > 0 ? field.max : 0;
    const min = field.min && field.min > 0 ? field.min : 0;

    const [keys, setKeys] = useState<string[]>(() => rows.map(() => rowKey()));
    const [open, setOpen] = useState<Record<string, boolean>>({});
    const current = syncKeys(keys, rows.length);
    if (current !== keys) setKeys(current);

    const isOpen = (key: string) => open[key] ?? rows.length <= 2;
    const allOpen = current.every(isOpen);
    const write = (next: Row[], nextKeys: string[]) => {
        setKeys(nextKeys);
        onChange(next);
    };

    const add = () => {
        const key = rowKey();
        setOpen((state) => ({ ...state, [key]: true }));
        write([...rows, newRow(subs)], [...current, key]);
    };

    return (
        <FieldGroup
            field={field}
            actions={
                rows.length > 1 ? (
                    <Button
                        variant="tertiary"
                        size="small"
                        onClick={() => setOpen(Object.fromEntries(current.map((key) => [key, !allOpen])))}
                    >
                        {allOpen ? __('Collapse all', 'taw-core') : __('Expand all', 'taw-core')}
                    </Button>
                ) : null
            }
        >
            {rows.length === 0 ? (
                <p className="taw-data-panel__group-empty">{__('No items yet.', 'taw-core')}</p>
            ) : (
                <ol className="taw-data-panel__rows">
                    {rows.map((row, index) => (
                        <RepeaterRow
                            key={current[index]}
                            field={field}
                            subs={subs}
                            row={row}
                            rowId={current[index]}
                            index={index}
                            count={rows.length}
                            open={isOpen(current[index])}
                            canRemove={rows.length > min}
                            onToggle={() =>
                                setOpen((state) => ({ ...state, [current[index]]: !isOpen(current[index]) }))
                            }
                            onMove={(from, to) => write(move(rows, from, to), move(current, from, to))}
                            onRemove={() =>
                                write(
                                    rows.filter((_, i) => i !== index),
                                    current.filter((_, i) => i !== index),
                                )
                            }
                            onChange={(id, next) =>
                                write(
                                    rows.map((other, i) => (i === index ? { ...other, [id]: next } : other)),
                                    current,
                                )
                            }
                        />
                    ))}
                </ol>
            )}
            {field.readonly ? null : (
                <div className="taw-data-panel__rows-footer">
                    <Button
                        className="taw-data-panel__add"
                        variant="secondary"
                        icon="plus-alt2"
                        disabled={max > 0 && rows.length >= max}
                        onClick={add}
                        __next40pxDefaultSize
                    >
                        {field.button_label ?? __('Add item', 'taw-core')}
                    </Button>
                    {max > 0 ? (
                        <p className="taw-data-panel__count">
                            {sprintf(
                                /* translators: 1: items, 2: most allowed. */ __('%1$d of %2$d', 'taw-core'),
                                rows.length,
                                max,
                            )}
                        </p>
                    ) : null}
                </div>
            )}
        </FieldGroup>
    );
}

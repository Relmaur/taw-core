/**
 * The `taw/tag` rich-text format (ADR-0011/0012): an atomic inline chip, as
 * core footnotes are (`contentEditable: false`). Clicking one opens a small
 * popover: edit its expression and condition (ADR-0013), refresh its stored
 * text, or remove it.
 */
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Button, Dashicon, Popover } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { registerFormatType, remove, useAnchor } from '@wordpress/rich-text';
import type { RichTextValue } from '@wordpress/rich-text';
import { ConditionSection, type Conditional } from './ConditionBuilder';
import { readCondition } from './conditions';
import { ExpressionEditor } from './ExpressionEditor';
import type { Config } from './logic';
import { previewExpression, previewTag } from './preview';
import {
    ATTRIBUTE,
    chipObject,
    CLASS_NAME,
    FORMAT,
    parseTag,
    storedText,
    toExpression,
    valueArgs,
    valueOptions,
    type TagArgs,
} from './tags';

interface EditProps {
    value: RichTextValue;
    onChange: (value: RichTextValue) => void;
    isObjectActive: boolean;
    activeObjectAttributes: Record<string, string>;
    contentRef: { current: HTMLElement | null };
}

/**
 * The value with the selected chip replaced (null: removed). The caret ends
 * after the chip, so it is no longer selected and its popover closes.
 */
function withChip(value: RichTextValue, object: ReturnType<typeof chipObject> | null): RichTextValue {
    const index = value.start ?? 0;
    // remove() keeps the arrays sparse: core's footnotes code breaks on `undefined` entries.
    if (object === null) return remove(value, index, index + 1);
    const replacements = value.replacements.slice();
    replacements[index] = object as unknown as (typeof replacements)[number];
    return { ...value, replacements, start: index + 1, end: index + 1 };
}

/** The caret moved past the selected chip: closes its popover without a change. */
function afterChip(value: RichTextValue): RichTextValue {
    const index = (value.start ?? 0) + 1;
    return { ...value, start: index, end: index };
}

function ChipPopover({ config, props, args }: { config: Config; props: EditProps; args: TagArgs }) {
    const postType = useSelect((select) => select('core/editor')?.getCurrentPostType?.() as string | undefined, []);
    const options = useMemo(() => valueOptions(config, postType), [config, postType]);
    const [expression, setExpression] = useState(() => toExpression(valueArgs(args), options));
    const [conditional, setConditional] = useState<Conditional>(() => {
        const condition = readCondition(args.if);
        return condition ? { if: condition, else: args.else } : {};
    });
    const [busy, setBusy] = useState(false);
    const anchor = useAnchor({
        editableContentElement: props.contentRef.current,
        settings: { ...settings(config), name: FORMAT, isActive: props.isObjectActive },
    } as unknown as Parameters<typeof useAnchor>[0]);

    const save = async (next: TagArgs) => {
        setBusy(true);
        // The chip's text is its value; the condition only decides on the front end.
        const value =
            next.expr !== undefined ? (await previewExpression(next.expr)).value : await previewTag(valueArgs(next));
        setBusy(false);
        props.onChange(
            withChip(props.value, chipObject(next, storedText(value, next.expr ?? next.tag ?? next.field ?? ''))),
        );
    };

    return (
        // Rendered at the end of <body>: the canvas's popover slot clips at the canvas edge, and the
        // editor (with its condition) is taller than core's own format popovers.
        createPortal(
            <Popover
                inline
                anchor={anchor}
                placement="bottom-start"
                className="taw-chip-popover"
                focusOnMount={false}
                shift
                resize={false}
                onClose={() => props.onChange(afterChip(props.value))}
            >
                <div className="taw-data-header">
                    <Dashicon icon="database" />
                    <strong>{__('TAW value', 'taw-core')}</strong>
                </div>
                <div className="taw-data-popup">
                    <ExpressionEditor value={expression} onChange={setExpression} options={options} autoFocus={false} />
                    <ConditionSection value={conditional} onChange={setConditional} options={options} />
                    <div className="taw-data-actions">
                        <Button
                            variant="tertiary"
                            isDestructive
                            onClick={() => props.onChange(withChip(props.value, null))}
                        >
                            {__('Remove', 'taw-core')}
                        </Button>
                        <Button variant="secondary" isBusy={busy} disabled={busy} onClick={() => void save(args)}>
                            {__('Refresh', 'taw-core')}
                        </Button>
                        <Button
                            variant="primary"
                            isBusy={busy}
                            disabled={busy || expression.trim() === ''}
                            onClick={() => void save({ expr: expression, ...conditional })}
                        >
                            {__('Save', 'taw-core')}
                        </Button>
                    </div>
                </div>
            </Popover>,
            document.body,
        )
    );
}

/**
 * Backspace/Delete next to a chip removes it, and Escape closes a selected
 * chip's popover. Rich text leaves deleting
 * objects to the browser, and Chrome won't remove a non-editable element at
 * the end of the text.
 */
function useChipDelete(props: EditProps): void {
    const latest = useRef(props);
    useEffect(() => {
        latest.current = props;
    });
    const { contentRef } = props;

    useEffect(() => {
        const element = contentRef.current;
        if (!element) return undefined;
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.defaultPrevented) return;
            const { value, onChange } = latest.current;
            if (value.start === undefined) return;
            // Escape with a chip selected closes its popover (it only sees Escape when focused).
            if (
                event.key === 'Escape' &&
                value.end === value.start + 1 &&
                value.replacements[value.start]?.type === FORMAT
            ) {
                event.preventDefault();
                event.stopPropagation();
                onChange(afterChip(value));
                return;
            }
            if ((event.key !== 'Backspace' && event.key !== 'Delete') || value.start !== value.end) return;
            const index = event.key === 'Backspace' ? value.start - 1 : value.start;
            if (index < 0 || value.replacements[index]?.type !== FORMAT) return;
            event.preventDefault();
            onChange(withChip({ ...value, start: index, end: index + 1 }, null));
        };
        element.addEventListener('keydown', onKeyDown, true);
        return () => element.removeEventListener('keydown', onKeyDown, true);
    }, [contentRef]);
}

function settings(config: Config) {
    return {
        title: __('TAW value', 'taw-core'),
        tagName: 'span',
        className: CLASS_NAME,
        attributes: { [ATTRIBUTE]: ATTRIBUTE },
        interactive: true,
        contentEditable: false,
        edit: function TawTagEdit(props: EditProps) {
            useChipDelete(props);
            if (!props.isObjectActive) return null;
            const json = props.activeObjectAttributes?.[ATTRIBUTE];
            const args = parseTag(json);
            // Keyed by the chip's JSON: another chip (or a saved change) starts fresh.
            return args ? <ChipPopover key={json} config={config} props={props} args={args} /> : null;
        },
    };
}

export function registerChips(config: Config): void {
    registerFormatType(FORMAT, settings(config) as unknown as Parameters<typeof registerFormatType>[1]);
}

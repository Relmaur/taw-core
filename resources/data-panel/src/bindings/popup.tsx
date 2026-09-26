/**
 * The TAW data popup (ADR-0012), opened from the block toolbar's database
 * button: a Fields tab (values grouped by fieldset) and an Expression tab.
 *
 * A mode switch at the top decides what picking does:
 *
 * - Inline: a chip where the caret is (default when the caret is in text);
 * - Block text: a `taw/field` binding on the block (read-only text, or, for
 *   an image field on an image, its ID/URL/alt as before).
 */
import React, { useEffect, useMemo, useState } from 'react';
import { Button, Dashicon, SearchControl, TabPanel } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { ExpressionEditor } from './ExpressionEditor';
import { connectedMetadata, planFor, withoutTawBindings, type BindingArgs, type Bindings, type Config } from './logic';
import { previewExpression, previewTag } from './preview';
import { caretIn, insertChip } from './richText';
import { groupOptions, storedText, valueOptions, type TagArgs, type ValueOption } from './tags';

/** The text attribute an expression binds, per block. */
export const TEXT_ATTRIBUTE: Record<string, string> = {
    'core/paragraph': 'content',
    'core/heading': 'content',
    'core/list-item': 'content',
    'core/button': 'text',
    'core/image': 'alt',
};

type Metadata = { bindings?: Bindings; [key: string]: unknown };
type Mode = 'inline' | 'block';

export interface PopupProps {
    config: Config;
    clientId: string;
    blockName: string;
    metadata: Metadata;
    onClose: () => void;
}

function withExpression(source: string, metadata: Metadata, attribute: string, expr: string): Metadata {
    return {
        ...metadata,
        bindings: {
            ...(withoutTawBindings(source, metadata.bindings) ?? {}),
            [attribute]: { source, args: { expr } as unknown as BindingArgs },
        },
    };
}

/** A short description of what the block is bound to, for the footer. */
function boundTo(source: string, metadata: Metadata, options: ValueOption[]): string | null {
    const binding = Object.values(metadata.bindings ?? {}).find((b) => b?.source === source);
    if (!binding) return null;
    const args = binding.args;
    if (typeof args.expr === 'string') return args.expr;
    const option = options.find(
        (o) => o.entry && o.entry.args.field === args.field && (o.entry.args.sub ?? '') === (args.sub ?? ''),
    );
    return option ? `@${option.name}` : args.field;
}

/** The last expression used per block this session, so reopening the popup shows it. */
const lastExpressions = new Map<string, string>();

/** What the Expression tab opens with: the block's own expression binding, else the last one used here. */
function initialExpression(source: string, clientId: string, metadata: Metadata, options: ValueOption[]): string {
    const binding = Object.values(metadata.bindings ?? {}).find((b) => b?.source === source);
    if (binding && typeof binding.args.expr === 'string') return binding.args.expr;
    const bound = boundTo(source, metadata, options);
    return bound ?? lastExpressions.get(clientId) ?? '';
}

export function DataPopup({ config, clientId, blockName, metadata, onClose }: PopupProps) {
    const postType = useSelect((select) => select('core/editor')?.getCurrentPostType?.() as string | undefined, []);
    // Re-read on selection changes: the caret decides whether inline works.
    useSelect((select) => select('core/block-editor').getSelectionStart(), []);
    const { updateBlockAttributes } = useDispatch('core/block-editor');
    const options = useMemo(() => valueOptions(config, postType), [config, postType]);

    const canInline = caretIn(clientId) !== null;
    const textAttribute = TEXT_ATTRIBUTE[blockName];
    const canBlock = textAttribute !== undefined || blockName === 'core/post-date';
    const [mode, setMode] = useState<Mode>(canInline || !canBlock ? 'inline' : 'block');
    const [search, setSearch] = useState('');
    const [expression, setExpression] = useState(() => initialExpression(config.source, clientId, metadata, options));
    const [previews, setPreviews] = useState<Record<string, string | null>>({});
    const bound = boundTo(config.source, metadata, options);
    const groups = groupOptions(options, search);

    // One batched request for every value's preview.
    useEffect(() => {
        let current = true;
        void Promise.all(options.map(async (o) => [o.key, await previewTag(o.args)] as const)).then((entries) => {
            if (current) setPreviews(Object.fromEntries(entries));
        });
        return () => {
            current = false;
        };
    }, [options]);

    const insert = async (args: TagArgs, label: string) => {
        if (args.expr !== undefined) lastExpressions.set(clientId, args.expr);
        const value = args.expr !== undefined ? (await previewExpression(args.expr)).value : await previewTag(args);
        if (insertChip(clientId, args, storedText(value, label))) onClose();
    };

    const bind = (next: Metadata) => {
        updateBlockAttributes(clientId, { metadata: next });
        onClose();
    };

    /** What picking an option does in block-text mode, or null when it doesn't fit this block. */
    const blockTextFor = (option: ValueOption): (() => void) | null => {
        const entry = option.entry;
        if (entry && planFor(config.source, blockName, entry)) {
            return () => {
                const next = connectedMetadata(config.source, blockName, metadata, entry);
                if (next) bind(next);
            };
        }
        if (!textAttribute) return null;
        return () => bind(withExpression(config.source, metadata, textAttribute, `@${option.name}`));
    };

    const pick = (option: ValueOption): (() => void) | null => {
        if (mode === 'inline') return canInline ? () => void insert(option.args, option.label) : null;
        return blockTextFor(option);
    };

    const modes: [Mode, string, string][] = [
        ['inline', __('Inline', 'taw-core'), 'editor-textcolor'],
        ['block', __('Block text', 'taw-core'), 'text'],
    ];

    const fieldsTab = (
        <div className="taw-data-fields">
            <SearchControl
                value={search}
                onChange={setSearch}
                label={__('Search values', 'taw-core')}
                placeholder={__('Search values', 'taw-core')}
                __nextHasNoMarginBottom
            />
            <div className="taw-data-groups">
                {groups.map(([group, items]) => (
                    <section key={group} className="taw-data-group">
                        <h3>
                            {group}
                            <span>{items.length}</span>
                        </h3>
                        {items.map((option) => {
                            const action = pick(option);
                            const preview = previews[option.key];
                            return (
                                <button
                                    key={option.key}
                                    type="button"
                                    className="taw-data-item"
                                    disabled={!action}
                                    onClick={() => action?.()}
                                >
                                    <span className="taw-data-item__main">
                                        <span className="taw-data-item__label">{option.label}</span>
                                        <code>@{option.name}</code>
                                    </span>
                                    <span className="taw-data-item__value" title={preview ?? undefined}>
                                        {preview ?? '—'}
                                    </span>
                                </button>
                            );
                        })}
                    </section>
                ))}
                {groups.length === 0 && (
                    <p className="taw-data-empty">{__('No values match your search.', 'taw-core')}</p>
                )}
            </div>
        </div>
    );

    const expressionAction =
        mode === 'inline'
            ? {
                  label: __('Insert inline', 'taw-core'),
                  disabled: !canInline,
                  run: () => void insert({ expr: expression }, expression),
              }
            : {
                  label: __('Use as block text', 'taw-core'),
                  disabled: !textAttribute,
                  run: () => {
                      if (textAttribute) {
                          lastExpressions.set(clientId, expression);
                          bind(withExpression(config.source, metadata, textAttribute, expression));
                      }
                  },
              };

    const expressionTab = (
        <div className="taw-data-expression">
            <ExpressionEditor value={expression} onChange={setExpression} options={options} />
            {mode === 'inline' && (
                <p className="taw-data-note">
                    <Dashicon icon="info-outline" />
                    {__('To change a value already in the text, click it there.', 'taw-core')}
                </p>
            )}
            <div className="taw-data-actions">
                <Button
                    variant="primary"
                    disabled={expressionAction.disabled || expression.trim() === ''}
                    onClick={expressionAction.run}
                >
                    {expressionAction.label}
                </Button>
            </div>
        </div>
    );

    return (
        <div className="taw-data-popup">
            <div className="taw-data-header">
                <Dashicon icon="database" />
                <strong>{__('TAW data', 'taw-core')}</strong>
                <Button icon="no-alt" size="small" label={__('Close', 'taw-core')} onClick={onClose} />
            </div>
            <div className="taw-data-mode" role="radiogroup" aria-label={__('Insert as', 'taw-core')}>
                <span className="taw-data-mode__label">{__('Insert as', 'taw-core')}</span>
                <div className="taw-data-mode__options">
                    {modes.map(([value, label, icon]) => (
                        <button
                            key={value}
                            type="button"
                            role="radio"
                            aria-checked={mode === value}
                            className={mode === value ? 'is-selected' : undefined}
                            disabled={value === 'block' && !canBlock}
                            onClick={() => setMode(value)}
                        >
                            <Dashicon icon={icon as never} />
                            {label}
                        </button>
                    ))}
                </div>
            </div>
            {mode === 'inline' && !canInline && (
                <p className="taw-data-note">
                    <Dashicon icon="info-outline" />
                    {__('Click into the text where the value should go, then open this again.', 'taw-core')}
                </p>
            )}
            <TabPanel
                className="taw-data-tabs"
                tabs={[
                    { name: 'fields', title: __('Fields', 'taw-core') },
                    { name: 'expression', title: __('Expression', 'taw-core') },
                ]}
            >
                {(tab: { name: string }) => (tab.name === 'fields' ? fieldsTab : expressionTab)}
            </TabPanel>
            {bound !== null && (
                <div className="taw-data-footer">
                    <span className="taw-data-footer__status">
                        <Dashicon icon="admin-links" />
                        <span>
                            {__('Block text:', 'taw-core')} <code>{bound}</code>
                        </span>
                    </span>
                    <Button
                        variant="link"
                        isDestructive
                        onClick={() =>
                            bind({ ...metadata, bindings: withoutTawBindings(config.source, metadata.bindings) })
                        }
                    >
                        {__('Disconnect', 'taw-core')}
                    </Button>
                </div>
            )}
        </div>
    );
}

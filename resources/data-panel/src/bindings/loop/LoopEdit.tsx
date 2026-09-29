/**
 * The TAW Loop block's editor (ADR-0014): a setup placeholder (source, then
 * a starting design), the sidebar's Loop panels, and a toolbar source switcher.
 * The items themselves are previewed by the Loop item block (ItemEdit).
 */
import React, { useEffect, useMemo, useState } from 'react';
import { BlockControls, InspectorControls, useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';
import { createBlock, type BlockInstance } from '@wordpress/blocks';
import {
    Button,
    Dashicon,
    PanelBody,
    Placeholder,
    RangeControl,
    SelectControl,
    TextControl,
    ToggleControl,
    ToolbarDropdownMenu,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { ConditionBuilder } from '../ConditionBuilder';
import { newCondition } from '../conditions';
import type { Config } from '../logic';
import { valueOptions } from '../tags';
import { loopChain } from './chain';
import {
    design,
    fieldSources,
    loopOptions,
    repeaterSubs,
    SOURCE_TYPES,
    sourceHelp,
    sourceLabel,
    type Design,
    type LoopAttributes,
    type LoopSource,
    type SourceType,
} from './data';

interface EditProps {
    clientId: string;
    attributes: LoopAttributes;
    setAttributes: (next: Partial<LoopAttributes>) => void;
}

const ICONS: Record<SourceType, string> = {
    repeater: 'list-view',
    related: 'admin-links',
    query: 'admin-post',
    terms: 'tag',
    images: 'format-gallery',
};

type Template = [string, Record<string, unknown>?, Template[]?];

function toBlocks(template: Template[]): BlockInstance[] {
    return template.map(([name, attributes, inner]) => createBlock(name, attributes ?? {}, toBlocks(inner ?? [])));
}

function usePostType(): string | undefined {
    return useSelect((select) => select('core/editor')?.getCurrentPostType?.() as string | undefined, []);
}

/** The enclosing loops (a string for useSelect, parsed here). */
function useOuterChain(clientId: string): LoopAttributes[] {
    const json = useSelect((select) => JSON.stringify(loopChain(clientId, select)), [clientId]);
    return useMemo(() => JSON.parse(json) as LoopAttributes[], [json]);
}

/** Source details: a field for field sources, a taxonomy or a post type otherwise. */
function SourceDetails({
    config,
    source,
    onChange,
    outer,
}: {
    config: Config;
    source: LoopSource;
    onChange: (next: LoopSource) => void;
    outer: LoopAttributes[];
}) {
    const postType = usePostType();
    const loop = config.loop;
    if (!loop || !source.type) return null;

    if (source.type === 'repeater' || source.type === 'related' || source.type === 'images') {
        const outerSubs = outer.length ? repeaterSubs(config, outer, postType) : null;
        const choices = fieldSources(config, source.type, postType, outerSubs);
        if (choices.length === 0) {
            return (
                <p className="taw-loop-setup__none">
                    <Dashicon icon="info-outline" />
                    {source.type === 'repeater'
                        ? __('No repeater fields here. Add one to a fieldset first.', 'taw-core')
                        : source.type === 'related'
                          ? __('No post select fields here. Add one to a fieldset first.', 'taw-core')
                          : __('No files or image fields here. Add one to a fieldset first.', 'taw-core')}
                </p>
            );
        }
        const value = source.field ? `${source.from ?? 'post'}:${source.field}` : '';
        return (
            <SelectControl
                label={__('Field', 'taw-core')}
                value={value}
                options={[
                    { value: '', label: __('Choose a field…', 'taw-core'), disabled: true },
                    ...choices.map((c) => ({ value: c.value, label: c.label })),
                ]}
                onChange={(next: string) => {
                    const [from, ...rest] = next.split(':');
                    onChange({ type: source.type, from: from as LoopSource['from'], field: rest.join(':') });
                }}
                __nextHasNoMarginBottom
                __next40pxDefaultSize
            />
        );
    }

    if (source.type === 'terms') {
        return (
            <>
                <SelectControl
                    label={__('Taxonomy', 'taw-core')}
                    value={source.taxonomy ?? ''}
                    options={[
                        { value: '', label: __('Choose a taxonomy…', 'taw-core'), disabled: true },
                        ...loop.taxonomies.map((t) => ({ value: t.name, label: t.label })),
                    ]}
                    onChange={(taxonomy: string) => onChange({ ...source, taxonomy })}
                    __nextHasNoMarginBottom
                    __next40pxDefaultSize
                />
                <SelectControl
                    label={__('Which terms', 'taw-core')}
                    value={source.scope ?? 'post'}
                    options={[
                        { value: 'post', label: __('This post’s terms', 'taw-core') },
                        { value: 'all', label: __('All terms', 'taw-core') },
                    ]}
                    onChange={(scope: string) => onChange({ ...source, scope: scope === 'all' ? 'all' : 'post' })}
                    __nextHasNoMarginBottom
                    __next40pxDefaultSize
                />
            </>
        );
    }

    return (
        <SelectControl
            label={__('Post type', 'taw-core')}
            value={source.postType ?? ''}
            options={[
                { value: '', label: __('Choose a post type…', 'taw-core'), disabled: true },
                ...loop.postTypes.map((t) => ({ value: t.name, label: t.label })),
            ]}
            onChange={(type: string) => onChange({ ...source, postType: type })}
            __nextHasNoMarginBottom
            __next40pxDefaultSize
        />
    );
}

function ready(source: LoopSource): boolean {
    switch (source.type) {
        case 'terms':
            return Boolean(source.taxonomy);
        case 'query':
            return Boolean(source.postType);
        case undefined:
            return false;
        default:
            return Boolean(source.field);
    }
}

function Setup({
    config,
    clientId,
    blockProps,
    onDone,
}: {
    config: Config;
    clientId: string;
    /** The loop's own block props (one useBlockProps per block). */
    blockProps: Record<string, unknown>;
    onDone: (source: LoopSource, kind: Design) => void;
}) {
    const [source, setSource] = useState<LoopSource>({});
    const outer = useOuterChain(clientId);

    return (
        <div {...blockProps}>
            <Placeholder
                icon="update"
                label={__('TAW Loop', 'taw-core')}
                instructions={
                    !source.type
                        ? __('Repeat a design for each item. What should it loop over?', 'taw-core')
                        : ready(source)
                          ? __('Pick a starting design. You can change every block afterwards.', 'taw-core')
                          : sourceHelp(source.type)
                }
                className="taw-loop-setup"
            >
                {!source.type && (
                    <div className="taw-loop-setup__sources">
                        {SOURCE_TYPES.map((type) => (
                            <button
                                key={type}
                                type="button"
                                className="taw-loop-setup__source"
                                onClick={() => setSource(type === 'terms' ? { type, scope: 'post' } : { type })}
                            >
                                <Dashicon icon={ICONS[type] as never} />
                                <strong>{sourceLabel(type)}</strong>
                                <span>{sourceHelp(type)}</span>
                            </button>
                        ))}
                    </div>
                )}
                {source.type && !ready(source) && (
                    <div className="taw-loop-setup__details">
                        <SourceDetails config={config} source={source} onChange={setSource} outer={outer} />
                        <Button variant="tertiary" onClick={() => setSource({})}>
                            {__('Back', 'taw-core')}
                        </Button>
                    </div>
                )}
                {source.type && ready(source) && (
                    <div className="taw-loop-setup__designs">
                        {(
                            [
                                ['list', __('List', 'taw-core'), 'editor-ul', __('One line per item.', 'taw-core')],
                                ['cards', __('Cards', 'taw-core'), 'grid-view', __('A grid of cards.', 'taw-core')],
                                [
                                    'blank',
                                    __('Start blank', 'taw-core'),
                                    'plus-alt2',
                                    __('An empty item to design yourself.', 'taw-core'),
                                ],
                            ] as [Design, string, string, string][]
                        ).map(([kind, label, icon, help]) => (
                            <button
                                key={kind}
                                type="button"
                                className="taw-loop-setup__source"
                                onClick={() => onDone(source, kind)}
                            >
                                <Dashicon icon={icon as never} />
                                <strong>{label}</strong>
                                <span>{help}</span>
                            </button>
                        ))}
                        <Button variant="tertiary" onClick={() => setSource({ type: source.type })}>
                            {__('Back', 'taw-core')}
                        </Button>
                    </div>
                )}
            </Placeholder>
        </div>
    );
}

function Inspector({ config, clientId, attributes, setAttributes }: EditProps & { config: Config }) {
    const postType = usePostType();
    const outer = useOuterChain(clientId);
    const chain = useMemo(() => [...outer, attributes], [outer, attributes]);
    const itemOptions = useMemo(
        () => [...loopOptions(config, chain, postType), ...valueOptions(config, postType)],
        [config, chain, postType],
    );
    const { source, order, layout } = attributes;
    const blocks = useSelect(
        (select) => select('core/block-editor').getBlocks(clientId) as BlockInstance[],
        [clientId],
    );
    const hasPagination = blocks.some((b) => b.name === 'taw/loop-pagination');
    const { insertBlock } = useDispatch('core/block-editor');
    const number = (value: string) => Math.max(0, Math.floor(Number(value) || 0));
    const taxonomies = (config.loop?.taxonomies ?? []).filter((t) => t.postTypes.includes(source.postType ?? ''));
    const sortable = itemOptions.filter((o) => !o.name.startsWith('loop.') && o.group !== __('Site', 'taw-core'));

    return (
        <InspectorControls>
            <PanelBody title={__('Source', 'taw-core')} className="taw-loop-panel">
                <SelectControl
                    label={__('Loop over', 'taw-core')}
                    value={source.type}
                    options={SOURCE_TYPES.map((type) => ({ value: type, label: sourceLabel(type) }))}
                    onChange={(type: string) =>
                        setAttributes({
                            source: { type: type as SourceType, ...(type === 'terms' ? { scope: 'post' } : {}) },
                        })
                    }
                    __nextHasNoMarginBottom
                    __next40pxDefaultSize
                />
                <SourceDetails
                    config={config}
                    source={source}
                    onChange={(next) => setAttributes({ source: next })}
                    outer={outer}
                />
                {source.type === 'query' && (
                    <>
                        {taxonomies.map((t) => (
                            <ToggleControl
                                key={t.name}
                                label={sprintf(__('Same %s as this post', 'taw-core'), t.label.toLowerCase())}
                                checked={source.terms?.[t.name] === 'current'}
                                onChange={(on: boolean) => {
                                    const terms = { ...(source.terms ?? {}) };
                                    if (on) terms[t.name] = 'current';
                                    else delete terms[t.name];
                                    setAttributes({ source: { ...source, terms } });
                                }}
                                __nextHasNoMarginBottom
                            />
                        ))}
                        <ToggleControl
                            label={__('Leave out this post', 'taw-core')}
                            checked={source.excludeCurrent === true}
                            onChange={(on: boolean) => setAttributes({ source: { ...source, excludeCurrent: on } })}
                            __nextHasNoMarginBottom
                        />
                        <ToggleControl
                            label={__('Same author as this post', 'taw-core')}
                            checked={source.author === 'current'}
                            onChange={(on: boolean) => {
                                const next = { ...source };
                                if (on) next.author = 'current';
                                else delete next.author;
                                setAttributes({ source: next });
                            }}
                            __nextHasNoMarginBottom
                        />
                        <TextControl
                            label={__('Search', 'taw-core')}
                            value={source.search ?? ''}
                            onChange={(search: string) => setAttributes({ source: { ...source, search } })}
                            __nextHasNoMarginBottom
                            __next40pxDefaultSize
                        />
                        <SelectControl
                            label={__('Sticky posts', 'taw-core')}
                            value={source.sticky ?? ''}
                            options={[
                                { value: '', label: __('Include', 'taw-core') },
                                { value: 'exclude', label: __('Leave out', 'taw-core') },
                                { value: 'only', label: __('Only sticky posts', 'taw-core') },
                            ]}
                            onChange={(sticky: string) =>
                                setAttributes({ source: { ...source, sticky: sticky as LoopSource['sticky'] } })
                            }
                            __nextHasNoMarginBottom
                            __next40pxDefaultSize
                        />
                        <SelectControl
                            label={__('Query order', 'taw-core')}
                            value={`${source.orderBy ?? 'date'}|${source.order ?? 'DESC'}` as string}
                            options={
                                [
                                    { value: 'date|DESC', label: __('Newest first', 'taw-core') },
                                    { value: 'date|ASC', label: __('Oldest first', 'taw-core') },
                                    { value: 'title|ASC', label: __('Title A → Z', 'taw-core') },
                                    { value: 'title|DESC', label: __('Title Z → A', 'taw-core') },
                                    { value: 'modified|DESC', label: __('Recently updated', 'taw-core') },
                                    { value: 'menu_order|ASC', label: __('Menu order', 'taw-core') },
                                    { value: 'comment_count|DESC', label: __('Most comments', 'taw-core') },
                                    { value: 'rand|DESC', label: __('Random', 'taw-core') },
                                ] as { value: string; label: string }[]
                            }
                            onChange={(value: string) => {
                                const [orderBy, dir] = value.split('|');
                                setAttributes({
                                    source: { ...source, orderBy, order: dir === 'ASC' ? 'ASC' : 'DESC' },
                                });
                            }}
                            __nextHasNoMarginBottom
                            __next40pxDefaultSize
                        />
                    </>
                )}
                {source.type === 'terms' && source.scope === 'all' && (
                    <ToggleControl
                        label={__('Hide terms without posts', 'taw-core')}
                        checked={source.hideEmpty !== false}
                        onChange={(on: boolean) => setAttributes({ source: { ...source, hideEmpty: on } })}
                        __nextHasNoMarginBottom
                    />
                )}
            </PanelBody>

            <PanelBody title={__('Items', 'taw-core')} className="taw-loop-panel">
                <SelectControl
                    label={__('Order by', 'taw-core')}
                    value={order.by ?? ''}
                    onChange={(by: string) => {
                        const option = sortable.find((o) => `@${o.name}` === by);
                        setAttributes({ order: { ...order, by, as: option?.isDate ? 'date' : order.as } });
                    }}
                    __nextHasNoMarginBottom
                    __next40pxDefaultSize
                >
                    <option value="">{__('The source’s order', 'taw-core')}</option>
                    <option value="random">{__('Random', 'taw-core')}</option>
                    {sortable.map((o) => (
                        <option key={o.key} value={`@${o.name}`}>
                            {`${o.group}: ${o.label}`}
                        </option>
                    ))}
                </SelectControl>
                {order.by !== 'random' && (
                    <SelectControl
                        label={__('Direction', 'taw-core')}
                        value={order.dir ?? 'asc'}
                        options={[
                            {
                                value: 'asc',
                                label: order.by
                                    ? __('Ascending (A → Z, 1 → 9)', 'taw-core')
                                    : __('As the source gives them', 'taw-core'),
                            },
                            {
                                value: 'desc',
                                label: order.by
                                    ? __('Descending (Z → A, 9 → 1)', 'taw-core')
                                    : __('Reversed', 'taw-core'),
                            },
                        ]}
                        onChange={(dir: string) =>
                            setAttributes({ order: { ...order, dir: dir === 'desc' ? 'desc' : 'asc' } })
                        }
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                    />
                )}
                {order.by && order.by !== 'random' && (
                    <SelectControl
                        label={__('Compare as', 'taw-core')}
                        value={order.as ?? 'text'}
                        options={[
                            { value: 'text', label: __('Text', 'taw-core') },
                            { value: 'number', label: __('Numbers', 'taw-core') },
                            { value: 'date', label: __('Dates', 'taw-core') },
                        ]}
                        onChange={(as: string) =>
                            setAttributes({ order: { ...order, as: as as LoopAttributes['order']['as'] } })
                        }
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                    />
                )}
                <div className="taw-loop-numbers">
                    <TextControl
                        type="number"
                        min={0}
                        label={__('Show at most', 'taw-core')}
                        help={__('0 = all (up to 200)', 'taw-core')}
                        value={String(attributes.limit || 0)}
                        onChange={(value: string) => setAttributes({ limit: number(value) })}
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                    />
                    <TextControl
                        type="number"
                        min={0}
                        label={__('Skip the first', 'taw-core')}
                        value={String(attributes.offset || 0)}
                        onChange={(value: string) => setAttributes({ offset: number(value) })}
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                    />
                </div>
                <TextControl
                    type="number"
                    min={0}
                    label={__('Items per page', 'taw-core')}
                    help={__('0 = no pages. Each loop pages on its own.', 'taw-core')}
                    value={String(attributes.perPage || 0)}
                    onChange={(value: string) => setAttributes({ perPage: number(value) })}
                    __nextHasNoMarginBottom
                    __next40pxDefaultSize
                />
                {attributes.perPage > 0 && !hasPagination && (
                    <Button
                        variant="secondary"
                        icon="plus-alt2"
                        onClick={() => insertBlock(createBlock('taw/loop-pagination'), undefined, clientId)}
                    >
                        {__('Add page links', 'taw-core')}
                    </Button>
                )}
            </PanelBody>

            <PanelBody
                title={__('Filter', 'taw-core')}
                initialOpen={Boolean(attributes.filter)}
                className="taw-loop-panel"
            >
                {attributes.filter ? (
                    <>
                        <ConditionBuilder
                            value={attributes.filter}
                            onChange={(filter) => setAttributes({ filter })}
                            options={itemOptions}
                            subject={__('an item', 'taw-core')}
                            showAnswer={false}
                        />
                        <div className="taw-data-actions">
                            <Button variant="link" isDestructive onClick={() => setAttributes({ filter: undefined })}>
                                {__('Remove filter', 'taw-core')}
                            </Button>
                        </div>
                    </>
                ) : (
                    <>
                        <p className="taw-vis__intro">
                            {__('Show only the items whose values match, e.g. awards from before 1970.', 'taw-core')}
                        </p>
                        <Button
                            variant="secondary"
                            icon="filter"
                            onClick={() =>
                                setAttributes({
                                    filter: newCondition(itemOptions[0] ? `@${itemOptions[0].name}` : '@post.title'),
                                })
                            }
                        >
                            {__('Add a filter', 'taw-core')}
                        </Button>
                    </>
                )}
            </PanelBody>

            <PanelBody title={__('Layout', 'taw-core')} className="taw-loop-panel">
                <SelectControl
                    label={__('Show items as', 'taw-core')}
                    value={layout.type ?? 'list'}
                    options={[
                        { value: 'list', label: __('A list', 'taw-core') },
                        { value: 'grid', label: __('A grid', 'taw-core') },
                    ]}
                    onChange={(type: string) =>
                        setAttributes({ layout: { ...layout, type: type === 'grid' ? 'grid' : 'list' } })
                    }
                    __nextHasNoMarginBottom
                    __next40pxDefaultSize
                />
                {layout.type === 'grid' && (
                    <RangeControl
                        label={__('Columns', 'taw-core')}
                        min={1}
                        max={6}
                        value={layout.columns ?? 3}
                        onChange={(columns?: number) => setAttributes({ layout: { ...layout, columns: columns ?? 3 } })}
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                    />
                )}
                <TextControl
                    label={__('Space between items', 'taw-core')}
                    help={__('e.g. 1.5rem or 24px', 'taw-core')}
                    value={layout.gap ?? ''}
                    onChange={(gap: string) => setAttributes({ layout: { ...layout, gap } })}
                    __nextHasNoMarginBottom
                    __next40pxDefaultSize
                />
                {layout.type !== 'grid' && (
                    <ToggleControl
                        label={__('Numbered list', 'taw-core')}
                        help={__('Marks the items up as an ordered list (<ol>).', 'taw-core')}
                        checked={attributes.ordered}
                        onChange={(ordered: boolean) => setAttributes({ ordered })}
                        __nextHasNoMarginBottom
                    />
                )}
            </PanelBody>
        </InspectorControls>
    );
}

export function LoopEdit(config: Config) {
    return function TawLoopEdit(props: EditProps) {
        const { clientId, attributes, setAttributes } = props;
        const { replaceInnerBlocks } = useDispatch('core/block-editor');
        const postType = usePostType();
        const outer = useOuterChain(clientId);
        const blockProps = useBlockProps({ className: 'taw-loop' });
        const innerBlocksProps = useInnerBlocksProps(blockProps, { templateLock: false });

        // A stable id per loop, for its page parameter (?taw-loop-<id>=2).
        useEffect(() => {
            if (!attributes.loopId) setAttributes({ loopId: Math.floor(Math.random() * 900000) + 100000 });
        }, [attributes.loopId, setAttributes]);

        if (!attributes.source?.type) {
            return (
                <Setup
                    config={config}
                    clientId={clientId}
                    blockProps={blockProps}
                    onDone={(source, kind) => {
                        const subs =
                            source.type === 'repeater'
                                ? (repeaterSubs(config, [...outer, { ...attributes, source }], postType) ?? [])
                                : [];
                        const picked = design(source.type as SourceType, kind, subs);
                        setAttributes({ source, layout: picked.layout });
                        replaceInnerBlocks(clientId, [
                            createBlock('taw/loop-item', {}, toBlocks(picked.item as Template[])),
                            createBlock('taw/loop-empty', {}, [
                                createBlock('core/paragraph', { content: __('No items yet.', 'taw-core') }),
                            ]),
                        ]);
                    }}
                />
            );
        }

        return (
            <>
                <BlockControls group="block">
                    <ToolbarDropdownMenu
                        icon={ICONS[attributes.source.type] as never}
                        label={sprintf(__('Loop over: %s', 'taw-core'), sourceLabel(attributes.source.type))}
                        controls={SOURCE_TYPES.map((type) => ({
                            title: sourceLabel(type),
                            icon: ICONS[type] as never,
                            isActive: attributes.source.type === type,
                            onClick: () =>
                                setAttributes({ source: { type, ...(type === 'terms' ? { scope: 'post' } : {}) } }),
                        }))}
                    />
                </BlockControls>
                <Inspector config={config} {...props} />
                <div {...innerBlocksProps} />
            </>
        );
    };
}

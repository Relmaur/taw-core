/**
 * Dynamic block settings in the editor (ADR-0016): a "TAW dynamic settings"
 * sidebar panel on every block (classes, colors and HTML attributes from
 * expressions, each with its answer for the edited post), and the canvas
 * showing the answered classes and colors. The server applies them on the
 * front end (BlockSettings); the canvas only previews them.
 */
import React, { useEffect, useMemo, useState } from 'react';
import { InspectorControls } from '@wordpress/block-editor';
import { Button, PanelBody, SelectControl, TextControl } from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useDispatch, useSelect } from '@wordpress/data';
import { addFilter } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';
import { ExpressionEditor, type PreviewResult } from './ExpressionEditor';
import type { Config } from './logic';
import { useValueOptions } from './loop/options';
import { previewSetting, type SettingAnswer } from './preview';
import {
    ATTRIBUTES,
    COLOR_SETTINGS,
    isAttribute,
    KEY,
    MAX_ATTRIBUTES,
    readSettings,
    withSetting,
    type Dropped,
    type Settings,
} from './settings';

type Metadata = { [key: string]: unknown };

const PRESET = /^var\(--wp--preset--color--([a-z0-9-]+)\)$/;

const isColor = (setting: string): boolean => (COLOR_SETTINGS as readonly string[]).includes(setting);

export function settingLabel(setting: string): string {
    switch (setting) {
        case 'classes':
            return __('Classes', 'taw-core');
        case 'color':
            return __('Text color', 'taw-core');
        case 'background':
            return __('Background', 'taw-core');
        case 'border':
            return __('Border color', 'taw-core');
        case 'id':
            return __('Anchor (id)', 'taw-core');
        case 'title':
            return __('Title', 'taw-core');
        case 'aria-label':
            return __('ARIA label', 'taw-core');
        case 'aria-description':
            return __('ARIA description', 'taw-core');
        default:
            return setting;
    }
}

function placeholderFor(setting: string): string {
    if (setting === 'classes') return "@if(@stock > 0, 'in-stock', 'sold-out')";
    if (isColor(setting)) return __('accent, #c0392b or @genre_color', 'taw-core');
    if (setting === 'id') return '@slug(@post.title)';
    return '@book_subtitle';
}

function droppedText(dropped: Dropped | null): string {
    switch (dropped) {
        case 'error':
            return __('It has an error, so it’s skipped.', 'taw-core');
        case 'not_a_color':
            return __(
                'Not a color, so it’s ignored. Use a palette color (accent) or a CSS color (#c0392b).',
                'taw-core',
            );
        case 'palette_only':
            return __('This site allows palette colors only, so it’s ignored.', 'taw-core');
        case 'empty':
            return __('Empty for this post: nothing changes.', 'taw-core');
        default:
            return __('Ignored.', 'taw-core');
    }
}

/** The editor's palette (slug → color and name), to draw swatches for preset variables. */
function usePalette(): Record<string, { color: string; name: string }> {
    const colors = useSelect(
        (select) =>
            ((select('core/block-editor')?.getSettings?.() as { colors?: unknown } | undefined)?.colors ?? null) as
                { slug: string; color: string; name?: string }[] | null,
        [],
    );
    return useMemo(
        () => Object.fromEntries((colors ?? []).map((c) => [c.slug, { color: c.color, name: c.name ?? c.slug }])),
        [colors],
    );
}

/** One setting's answer for the edited post: what the front end will apply, or why it won't. */
function Answer({ setting, answer }: { setting: string; answer: Pick<SettingAnswer, 'value' | 'dropped'> }) {
    const palette = usePalette();
    if (answer.value === null) return <em className="taw-dyn-answer__note">{droppedText(answer.dropped)}</em>;
    if (isColor(setting)) {
        const slug = PRESET.exec(answer.value)?.[1];
        const preset = slug !== undefined ? palette[slug] : undefined;
        return (
            <span className="taw-dyn-answer__color">
                <span className="taw-dyn-swatch" style={{ background: preset?.color ?? answer.value }} />
                <code>{preset ? preset.name : (slug ?? answer.value)}</code>
            </span>
        );
    }
    if (setting === 'classes') {
        return (
            <span className="taw-dyn-answer__classes">
                {answer.value.split(' ').map((c) => (
                    <code key={c}>.{c}</code>
                ))}
            </span>
        );
    }
    return <code>{answer.value}</code>;
}

/** A setting's answer, asked once the expression stops changing; undefined while it loads. */
function useSettingAnswer(setting: string, expr: string, clientId: string): SettingAnswer | null | undefined {
    const [answer, setAnswer] = useState<{ for: string; value: SettingAnswer | null } | null>(null);
    const key = `${setting}\n${expr}`;
    useEffect(() => {
        if (expr.trim() === '') return undefined;
        let current = true;
        const timer = setTimeout(() => {
            void previewSetting(setting, expr, clientId).then((result) => {
                if (current) setAnswer({ for: key, value: result });
            });
        }, 300);
        return () => {
            current = false;
            clearTimeout(timer);
        };
    }, [key, setting, expr, clientId]);
    return answer && answer.for === key ? answer.value : undefined;
}

function SettingRow({
    setting,
    expr,
    open,
    onToggle,
    onChange,
    onRemove,
    clientId,
    options,
}: {
    setting: string;
    expr: string;
    open: boolean;
    onToggle: () => void;
    onChange: (expr: string) => void;
    onRemove?: () => void;
    clientId: string;
    options: ReturnType<typeof useValueOptions>;
}) {
    const answer = useSettingAnswer(setting, open ? '' : expr, clientId);
    const label = settingLabel(setting);
    const preview = (value: string): Promise<PreviewResult> =>
        previewSetting(setting, value, clientId).then((a) => ({
            value: a?.value ?? '',
            errors: a?.errors ?? [],
            dropped: a?.dropped ?? null,
        }));

    return (
        <div className={open ? 'taw-dyn-row is-open' : 'taw-dyn-row'}>
            <div className="taw-dyn-row__head">
                <span className="taw-dyn-row__label">
                    {isAttribute(setting) && !ATTRIBUTES.includes(setting) ? <code>{setting}</code> : label}
                </span>
                <Button variant="link" onClick={onToggle} aria-expanded={open}>
                    {open ? __('Done', 'taw-core') : expr ? __('Edit', 'taw-core') : __('Add', 'taw-core')}
                </Button>
                {onRemove && (
                    <Button
                        icon="no-alt"
                        size="small"
                        /* translators: %s: a setting, e.g. "Title" or "data-genre" */
                        label={sprintf(__('Remove %s', 'taw-core'), label)}
                        onClick={onRemove}
                    />
                )}
            </div>
            {open ? (
                <ExpressionEditor
                    compact
                    value={expr}
                    onChange={onChange}
                    options={options}
                    label={label}
                    placeholder={placeholderFor(setting)}
                    preview={preview}
                    renderPreview={(result) => (
                        <Answer
                            setting={setting}
                            answer={{
                                value: result.value === '' ? null : result.value,
                                dropped: (result.dropped as Dropped | null) ?? (result.value === '' ? 'empty' : null),
                            }}
                        />
                    )}
                />
            ) : (
                expr !== '' && (
                    <>
                        <code className="taw-dyn-row__expr">{expr}</code>
                        <div className="taw-dyn-answer">
                            {answer === undefined ? (
                                <em className="taw-dyn-answer__note">{__('Loading…', 'taw-core')}</em>
                            ) : answer === null ? null : (
                                <Answer setting={setting} answer={answer} />
                            )}
                        </div>
                    </>
                )
            )}
        </div>
    );
}

export function DynamicSettingsEditor({
    config,
    clientId,
    metadata,
}: {
    config: Config;
    clientId: string;
    metadata: Metadata;
}) {
    const options = useValueOptions(config, clientId);
    const { updateBlockAttributes } = useDispatch('core/block-editor');
    const settings = readSettings(metadata[KEY]);
    const [openKey, setOpenKey] = useState<string | null>(null);
    const [addingData, setAddingData] = useState(false);
    const [dataName, setDataName] = useState('');

    const save = (setting: string, expr: string) =>
        updateBlockAttributes(clientId, { metadata: withSetting(metadata, setting, expr) });
    const toggle = (setting: string) => setOpenKey(openKey === setting ? null : setting);

    // An attribute being added shows before it has an expression.
    const attributes = Object.keys(settings.attributes ?? {});
    if (openKey !== null && isAttribute(openKey) && !attributes.includes(openKey)) attributes.push(openKey);
    const canAdd = attributes.length < MAX_ATTRIBUTES;
    const dataAttribute = `data-${dataName.trim()}`;

    const row = (setting: string, expr: string, removable = false) => (
        <SettingRow
            key={setting}
            setting={setting}
            expr={expr}
            open={openKey === setting}
            onToggle={() => toggle(setting)}
            onChange={(next) => save(setting, next)}
            onRemove={
                removable
                    ? () => {
                          save(setting, '');
                          if (openKey === setting) setOpenKey(null);
                      }
                    : undefined
            }
            clientId={clientId}
            options={options}
        />
    );

    return (
        <div className="taw-dyn">
            <p className="taw-dyn__intro">
                {__(
                    'Set this block’s classes, colors and HTML attributes from values, e.g. a background from the genre’s color. They apply on the site; the canvas previews them.',
                    'taw-core',
                )}
            </p>
            {row('classes', settings.classes ?? '')}
            {COLOR_SETTINGS.map((setting) => row(setting, settings[setting] ?? ''))}
            <h3 className="taw-dyn__heading">{__('HTML attributes', 'taw-core')}</h3>
            {attributes.map((name) => row(name, settings.attributes?.[name] ?? '', true))}
            {canAdd && (
                <div className="taw-dyn__add">
                    <SelectControl
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                        label={__('Add an attribute', 'taw-core')}
                        value=""
                        options={[
                            { label: __('Choose…', 'taw-core'), value: '' },
                            ...ATTRIBUTES.filter((name) => !attributes.includes(name)).map((name) => ({
                                label: settingLabel(name),
                                value: name,
                            })),
                            { label: __('Data attribute (data-…)', 'taw-core'), value: 'data' },
                        ]}
                        onChange={(value: string) => {
                            if (value === 'data') setAddingData(true);
                            else if (value) {
                                setAddingData(false);
                                setOpenKey(value);
                            }
                        }}
                    />
                    {addingData && (
                        <div className="taw-dyn__data">
                            <TextControl
                                __nextHasNoMarginBottom
                                __next40pxDefaultSize
                                label={__('Name after data-', 'taw-core')}
                                value={dataName}
                                onChange={setDataName}
                                help={
                                    dataName.trim() !== '' && !isAttribute(dataAttribute)
                                        ? __('Use lowercase letters, numbers and single dashes.', 'taw-core')
                                        : undefined
                                }
                            />
                            <Button
                                variant="secondary"
                                disabled={!isAttribute(dataAttribute) || attributes.includes(dataAttribute)}
                                onClick={() => {
                                    setOpenKey(dataAttribute);
                                    setAddingData(false);
                                    setDataName('');
                                }}
                            >
                                {__('Add', 'taw-core')}
                            </Button>
                        </div>
                    )}
                </div>
            )}
            <p className="taw-dyn__note">
                {__(
                    'Values are cleaned before they’re used: colors must be a palette color or a CSS color, and anything unsafe is left out.',
                    'taw-core',
                )}
            </p>
        </div>
    );
}

/** What the canvas shows for a block: its answered classes and colors (attributes don't show). */
interface CanvasPlan {
    classes: string[];
    colors: Partial<Record<(typeof COLOR_SETTINGS)[number], string>>;
}

function useCanvasPlan(settings: Settings, clientId: string): CanvasPlan | null {
    const visible: [string, string][] = [];
    if (settings.classes) visible.push(['classes', settings.classes]);
    for (const setting of COLOR_SETTINGS) if (settings[setting]) visible.push([setting, settings[setting]!]);
    const json = JSON.stringify(visible);
    const [plan, setPlan] = useState<{ for: string; plan: CanvasPlan } | null>(null);

    useEffect(() => {
        const entries = JSON.parse(json) as [string, string][];
        if (entries.length === 0) return undefined;
        let current = true;
        const timer = setTimeout(() => {
            void Promise.all(entries.map(([setting, expr]) => previewSetting(setting, expr, clientId))).then(
                (answers) => {
                    if (!current) return;
                    const next: CanvasPlan = { classes: [], colors: {} };
                    entries.forEach(([setting], index) => {
                        const value = answers[index]?.value;
                        if (!value) return;
                        if (setting === 'classes') next.classes = value.split(' ');
                        else next.colors[setting as (typeof COLOR_SETTINGS)[number]] = value;
                    });
                    setPlan({ for: json, plan: next });
                },
            );
        }, 300);
        return () => {
            current = false;
            clearTimeout(timer);
        };
    }, [json, clientId]);

    return plan && plan.for === json ? plan.plan : null;
}

const CANVAS_VARIABLE = { color: '--taw-dyn-color', background: '--taw-dyn-bg', border: '--taw-dyn-border' } as const;
const CANVAS_CLASS = { color: 'taw-dyn-color', background: 'taw-dyn-bg', border: 'taw-dyn-border' } as const;

interface EditProps {
    name: string;
    clientId: string;
    isSelected: boolean;
    attributes: { metadata?: Metadata };
}

interface ListProps {
    clientId: string;
    className?: string;
    attributes: { metadata?: Metadata };
    wrapperProps?: { style?: React.CSSProperties; [key: string]: unknown };
}

export function registerDynamicSettings(config: Config): void {
    addFilter(
        'editor.BlockEdit',
        'taw/dynamic-settings-panel',
        createHigherOrderComponent(
            (BlockEdit: React.ComponentType<EditProps>) =>
                function WithTawDynamicSettings(props: EditProps) {
                    const metadata = props.attributes.metadata ?? {};
                    return (
                        <>
                            <BlockEdit {...props} />
                            {props.isSelected && (
                                <InspectorControls>
                                    <PanelBody
                                        title={__('TAW dynamic settings', 'taw-core')}
                                        initialOpen={Object.keys(readSettings(metadata[KEY])).length > 0}
                                        className="taw-dyn-panel"
                                    >
                                        <DynamicSettingsEditor
                                            config={config}
                                            clientId={props.clientId}
                                            metadata={metadata}
                                        />
                                    </PanelBody>
                                </InspectorControls>
                            )}
                        </>
                    );
                },
            'withTawDynamicSettings',
        ),
    );

    addFilter(
        'editor.BlockListBlock',
        'taw/dynamic-settings-canvas',
        createHigherOrderComponent(
            (BlockListBlock: React.ComponentType<ListProps>) =>
                function WithTawDynamicCanvas(props: ListProps) {
                    // One element type either way: switching would remount the block.
                    const settings = readSettings(props.attributes?.metadata?.[KEY]);
                    const plan = useCanvasPlan(settings, props.clientId);
                    if (Object.keys(settings).length === 0) return <BlockListBlock {...props} />;

                    const style: Record<string, string> = { ...(props.wrapperProps?.style as Record<string, string>) };
                    const classes = [props.className, 'taw-dynamic', ...(plan?.classes ?? [])];
                    for (const setting of COLOR_SETTINGS) {
                        const value = plan?.colors[setting];
                        if (!value) continue;
                        style[CANVAS_VARIABLE[setting]] = value;
                        classes.push(CANVAS_CLASS[setting]);
                    }
                    return (
                        <BlockListBlock
                            {...props}
                            className={classes.filter(Boolean).join(' ')}
                            wrapperProps={{ ...props.wrapperProps, style: style as React.CSSProperties }}
                        />
                    );
                },
            'withTawDynamicCanvas',
        ),
    );
}

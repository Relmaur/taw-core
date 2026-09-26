/**
 * Whole-block conditions (ADR-0013): `metadata.tawShowIf`, edited in the TAW
 * data popup's Visibility tab and in a "TAW visibility" sidebar panel on every
 * block. Conditional blocks get a dashed outline and a label in the canvas;
 * blocks hidden for the edited post are dimmed.
 */
import React, { useMemo } from 'react';
import { InspectorControls } from '@wordpress/block-editor';
import { Button, PanelBody } from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useDispatch, useSelect } from '@wordpress/data';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { ConditionBuilder, useConditionAnswer } from './ConditionBuilder';
import { conditionErrors, newCondition, readCondition, type Condition } from './conditions';
import type { Config } from './logic';
import { valueOptions } from './tags';

export const KEY = 'tawShowIf';

const NONE: Condition = { rules: [] };

type Metadata = { [key: string]: unknown };

export function VisibilityEditor({
    config,
    clientId,
    metadata,
}: {
    config: Config;
    clientId: string;
    metadata: Metadata;
}) {
    const postType = useSelect((select) => select('core/editor')?.getCurrentPostType?.() as string | undefined, []);
    const options = useMemo(() => valueOptions(config, postType), [config, postType]);
    const { updateBlockAttributes } = useDispatch('core/block-editor');
    const condition = readCondition(metadata[KEY]);

    const save = (next: Condition | null) => {
        const rest = { ...metadata };
        delete rest[KEY];
        updateBlockAttributes(clientId, { metadata: next ? { ...rest, [KEY]: next } : rest });
    };

    if (!condition) {
        return (
            <div className="taw-vis">
                <p className="taw-vis__intro">
                    {__(
                        'Show this block only when values match, e.g. a Buy button only when the buy link is set.',
                        'taw-core',
                    )}
                </p>
                <Button
                    variant="secondary"
                    icon="visibility"
                    onClick={() => save(newCondition(options[0] ? `@${options[0].name}` : '@post.title'))}
                >
                    {__('Add a condition', 'taw-core')}
                </Button>
            </div>
        );
    }

    return (
        <div className="taw-vis">
            <ConditionBuilder
                value={condition}
                onChange={save}
                options={options}
                subject={__('this block', 'taw-core')}
            />
            <div className="taw-data-actions">
                <Button variant="link" isDestructive onClick={() => save(null)}>
                    {__('Remove condition', 'taw-core')}
                </Button>
            </div>
            <p className="taw-vis__note">
                {__(
                    'Hidden blocks are still in the post content: this changes what shows, not who can read it.',
                    'taw-core',
                )}
            </p>
        </div>
    );
}

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
    wrapperProps?: Record<string, unknown>;
}

export function registerVisibility(config: Config): void {
    addFilter(
        'editor.BlockEdit',
        'taw/visibility-panel',
        createHigherOrderComponent(
            (BlockEdit: React.ComponentType<EditProps>) =>
                function WithTawVisibility(props: EditProps) {
                    const metadata = props.attributes.metadata ?? {};
                    return (
                        <>
                            <BlockEdit {...props} />
                            {props.isSelected && (
                                <InspectorControls>
                                    <PanelBody
                                        title={__('TAW visibility', 'taw-core')}
                                        initialOpen={readCondition(metadata[KEY]) !== null}
                                        className="taw-vis-panel"
                                    >
                                        <VisibilityEditor
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
            'withTawVisibility',
        ),
    );

    addFilter(
        'editor.BlockListBlock',
        'taw/visibility-cue',
        createHigherOrderComponent(
            (BlockListBlock: React.ComponentType<ListProps>) =>
                function WithTawVisibilityCue(props: ListProps) {
                    // One element type either way: switching would remount the block (and close its popup).
                    const condition = readCondition(props.attributes?.metadata?.[KEY]);
                    const valid = condition !== null && conditionErrors(condition).length === 0;
                    const answer = useConditionAnswer(condition ?? NONE, valid);
                    if (!condition) return <BlockListBlock {...props} />;

                    const label = !valid
                        ? __('Condition incomplete', 'taw-core')
                        : answer === null
                          ? __('Conditional', 'taw-core')
                          : answer.shown
                            ? __('Conditional · shown for this post', 'taw-core')
                            : __('Conditional · hidden for this post', 'taw-core');
                    const hidden = !valid || answer?.shown === false;
                    return (
                        <BlockListBlock
                            {...props}
                            className={[props.className, 'taw-conditional', hidden ? 'is-taw-hidden' : '']
                                .filter(Boolean)
                                .join(' ')}
                            wrapperProps={{ ...props.wrapperProps, 'data-taw-condition': label }}
                        />
                    );
                },
            'withTawVisibilityCue',
        ),
    );
}

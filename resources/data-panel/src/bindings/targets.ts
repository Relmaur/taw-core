/**
 * What an expression fills in a block (ADR-0012, ADR-0015): its text, a
 * button's link, an image (`as: "image"`: its ID, URL and alt) or an image's
 * alt text, and the bindings that do it.
 */
import { __ } from '@wordpress/i18n';
import type { Conditional } from './ConditionBuilder';
import { withoutTawBindings, type BindingArgs, type Bindings } from './logic';

type Metadata = { bindings?: Bindings; [key: string]: unknown };

/** The text attribute an expression binds, per block. */
export const TEXT_ATTRIBUTE: Record<string, string> = {
    'core/paragraph': 'content',
    'core/heading': 'content',
    'core/list-item': 'content',
    'core/button': 'text',
    'core/image': 'alt',
};

/** What an expression can fill in a block (ADR-0015); the first is the default. */
export interface ExpressionTarget {
    key: string;
    label: string;
    attributes: string[];
    as?: 'image';
}

export function expressionTargets(blockName: string): ExpressionTarget[] {
    switch (blockName) {
        case 'core/button':
            return [
                { key: 'text', label: __('Button text', 'taw-core'), attributes: ['text'] },
                { key: 'link', label: __('Link', 'taw-core'), attributes: ['url'] },
            ];
        case 'core/image':
            return [
                { key: 'image', label: __('Image', 'taw-core'), attributes: ['id', 'url', 'alt'], as: 'image' },
                { key: 'alt', label: __('Alt text', 'taw-core'), attributes: ['alt'] },
            ];
        default:
            return TEXT_ATTRIBUTE[blockName]
                ? [{ key: 'text', label: __('Text', 'taw-core'), attributes: [TEXT_ATTRIBUTE[blockName]] }]
                : [];
    }
}

/**
 * The block's bindings with an expression on $target's attributes. A text
 * target replaces every TAW binding (as before); a link or an image keeps the
 * block's other ones (a button keeps its bound text when its link is bound).
 */
export function withExpression(
    source: string,
    metadata: Metadata,
    target: ExpressionTarget | string,
    expr: string,
    conditional: Conditional = {},
): Metadata {
    const { attributes, as } = typeof target === 'string' ? { attributes: [target], as: undefined } : target;
    const args: Record<string, unknown> = { expr };
    if (as) args.as = as;
    if (conditional.if !== undefined) {
        args.if = conditional.if;
        if (conditional.else?.trim()) args.else = conditional.else;
    }
    const isText = typeof target === 'string' || target.key === 'text';
    const kept = isText
        ? (withoutTawBindings(source, metadata.bindings) ?? {})
        : Object.fromEntries(
              Object.entries(metadata.bindings ?? {}).filter(([attribute]) => !attributes.includes(attribute)),
          );
    return {
        ...metadata,
        bindings: {
            ...kept,
            ...Object.fromEntries(
                attributes.map((attribute) => [attribute, { source, args: args as unknown as BindingArgs }]),
            ),
        },
    };
}

/**
 * What an expression fills in a block (ADR-0012, ADR-0015, ADR-0016): its
 * text, a button's link, new tab and rel, an image (`as: "image"`: its ID, URL
 * and alt) or its alt text, caption and title, a navigation link's URL, a post
 * date, and the bindings that do it.
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
                { key: 'newTab', label: __('Open in new tab', 'taw-core'), attributes: ['linkTarget'] },
                { key: 'rel', label: __('Link rel', 'taw-core'), attributes: ['rel'] },
            ];
        case 'core/image':
            return [
                { key: 'image', label: __('Image', 'taw-core'), attributes: ['id', 'url', 'alt'], as: 'image' },
                { key: 'alt', label: __('Alt text', 'taw-core'), attributes: ['alt'] },
                { key: 'caption', label: __('Caption', 'taw-core'), attributes: ['caption'] },
                { key: 'title', label: __('Title', 'taw-core'), attributes: ['title'] },
            ];
        case 'core/navigation-link':
        case 'core/navigation-submenu':
            return [{ key: 'link', label: __('Link', 'taw-core'), attributes: ['url'] }];
        case 'core/post-date':
            return [{ key: 'date', label: __('Date', 'taw-core'), attributes: ['datetime'] }];
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

/** Whether an "Open in new tab" result opens a new tab (as the server reads it: not empty, not "0"). */
export function opensNewTab(value: string): boolean {
    const trimmed = value.trim();
    return trimmed !== '' && trimmed !== '0';
}

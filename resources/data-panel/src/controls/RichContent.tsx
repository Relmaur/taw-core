import React, { useState } from 'react';
import { BaseControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ControlProps } from '../types';
import { excerpt } from '../rich/html';
import MiniEditor from '../rich/MiniEditor';
import { fieldLabel } from './label';

/**
 * wysiwyg: a preview in the sidebar (it's too narrow to write in) and an
 * Edit button that opens the mini block editor. `teeny` fields get a
 * text-only block set.
 */
export function Wysiwyg({ field, value, onChange }: ControlProps) {
    const [open, setOpen] = useState(false);
    const html = typeof value === 'string' ? value : '';
    const preview = excerpt(html);
    const id = `taw-data-${field.id}`;

    return (
        <BaseControl __nextHasNoMarginBottom id={id} label={fieldLabel(field)} help={field.description}>
            <div className={`taw-data-panel__rich${preview ? '' : ' is-empty'}`}>
                <p className="taw-data-panel__rich-preview">{preview || __('No content yet', 'taw-core')}</p>
                <Button
                    id={id}
                    variant="secondary"
                    size="compact"
                    icon="edit"
                    disabled={field.readonly}
                    onClick={() => setOpen(true)}
                >
                    {preview ? __('Edit', 'taw-core') : __('Write', 'taw-core')}
                </Button>
            </div>
            {open ? <MiniEditor field={field} html={html} onApply={onChange} onClose={() => setOpen(false)} /> : null}
        </BaseControl>
    );
}

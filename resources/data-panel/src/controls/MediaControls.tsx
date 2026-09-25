import React from 'react';
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import type { MediaItem } from '@wordpress/block-editor';
import { BaseControl, Button, Dashicon, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import type { ControlProps, FieldDescriptor } from '../types';
import { fieldLabel } from './label';

/** What the panel shows for an attachment (from core-data's media record). */
interface Attachment {
    id: number;
    name: string;
    thumb: string;
    isImage: boolean;
}

interface MediaRecord {
    id: number;
    source_url?: string;
    mime_type?: string;
    title?: { rendered?: string; raw?: string };
    media_details?: { sizes?: Record<string, { source_url: string }> };
}

/** An attachment by id: undefined while loading, null when it's gone. */
function useAttachment(id: number): Attachment | null | undefined {
    return useSelect(
        (select) => {
            if (!id) return null;
            const core = select('core');
            const args = ['postType', 'attachment', id];
            const record = core.getEntityRecord(...args) as MediaRecord | undefined;
            if (record) return toAttachment(record);
            return core.hasFinishedResolution?.('getEntityRecord', args) ? null : undefined;
        },
        [id],
    );
}

function toAttachment(record: MediaRecord): Attachment {
    const sizes = record.media_details?.sizes ?? {};
    const isImage = (record.mime_type ?? '').startsWith('image/');
    const url = record.source_url ?? '';
    return {
        id: record.id,
        name: record.title?.raw || record.title?.rendered || url.split('/').pop() || `#${record.id}`,
        thumb: isImage ? (sizes.medium?.source_url ?? sizes.thumbnail?.source_url ?? url) : '',
        isImage,
    };
}

const asId = (value: unknown): number => {
    const id = Number(value);
    return Number.isInteger(id) && id > 0 ? id : 0;
};

/** image: an attachment id (0 when empty), like the metabox. */
export function Image({ field, value, onChange }: ControlProps) {
    const id = asId(value);
    const attachment = useAttachment(id);
    const controlId = `taw-data-${field.id}`;

    return (
        <BaseControl __nextHasNoMarginBottom id={controlId} label={fieldLabel(field)} help={field.description}>
            <MediaUploadCheck fallback={<Fallback />}>
                <MediaUpload
                    title={field.label ?? field.id}
                    allowedTypes={['image']}
                    value={id || undefined}
                    onSelect={(media) => onChange(Array.isArray(media) ? (media[0]?.id ?? 0) : media.id)}
                    render={({ open }) =>
                        id ? (
                            <div className="taw-data-panel__image">
                                <Button
                                    id={controlId}
                                    className="taw-data-panel__image-preview"
                                    onClick={open}
                                    disabled={field.readonly}
                                    label={__('Replace image', 'taw-core')}
                                >
                                    {attachment === undefined ? (
                                        <Spinner />
                                    ) : attachment ? (
                                        <img src={attachment.thumb} alt="" />
                                    ) : (
                                        <span className="taw-data-panel__missing">
                                            {sprintf(
                                                /* translators: %d: attachment ID. */
                                                __('Image #%d is no longer in the media library', 'taw-core'),
                                                id,
                                            )}
                                        </span>
                                    )}
                                </Button>
                                {field.readonly ? null : (
                                    <div className="taw-data-panel__actions">
                                        <Button variant="secondary" size="compact" onClick={open}>
                                            {__('Replace', 'taw-core')}
                                        </Button>
                                        <Button
                                            variant="tertiary"
                                            size="compact"
                                            isDestructive
                                            onClick={() => onChange(0)}
                                        >
                                            {__('Remove', 'taw-core')}
                                        </Button>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <Button
                                id={controlId}
                                className="taw-data-panel__dropzone"
                                onClick={open}
                                disabled={field.readonly}
                            >
                                {__('Choose an image', 'taw-core')}
                            </Button>
                        )
                    }
                />
            </MediaUploadCheck>
        </BaseControl>
    );
}

function Fallback() {
    return <p className="taw-data-panel__pending">{__('You can’t upload or choose media.', 'taw-core')}</p>;
}

function FileItem({
    id,
    field,
    index,
    count,
    onMove,
    onRemove,
}: {
    id: number;
    field: FieldDescriptor;
    index: number;
    count: number;
    onMove: (from: number, to: number) => void;
    onRemove: () => void;
}) {
    const attachment = useAttachment(id);
    const name =
        attachment?.name ??
        (attachment === null
            ? sprintf(/* translators: %d: attachment ID. */ __('File #%d (missing)', 'taw-core'), id)
            : '…');

    return (
        <li className="taw-data-panel__file">
            <span className="taw-data-panel__file-thumb">
                {attachment === undefined ? (
                    <Spinner />
                ) : attachment?.isImage ? (
                    <img src={attachment.thumb} alt="" />
                ) : (
                    <Dashicon icon="media-default" />
                )}
            </span>
            <span className="taw-data-panel__file-name">{name}</span>
            {field.readonly ? null : (
                <span className="taw-data-panel__file-actions">
                    <Button
                        size="small"
                        icon="arrow-up-alt2"
                        label={__('Move up', 'taw-core')}
                        disabled={index === 0}
                        onClick={() => onMove(index, index - 1)}
                    />
                    <Button
                        size="small"
                        icon="arrow-down-alt2"
                        label={__('Move down', 'taw-core')}
                        disabled={index === count - 1}
                        onClick={() => onMove(index, index + 1)}
                    />
                    <Button
                        size="small"
                        icon="no-alt"
                        className="taw-data-panel__remove"
                        label={sprintf(/* translators: %s: file name. */ __('Remove %s', 'taw-core'), name)}
                        onClick={onRemove}
                    />
                </span>
            )}
        </li>
    );
}

/** files: a list of attachment ids (the decoded `taw_<id>` REST field). */
export function Files({ field, value, onChange }: ControlProps) {
    const ids = (Array.isArray(value) ? value : []).map(asId).filter(Boolean);
    const limit = field.limit && field.limit > 0 ? field.limit : 0;
    const full = limit > 0 && ids.length >= limit;
    const controlId = `taw-data-${field.id}`;

    const add = (media: MediaItem | MediaItem[]) => {
        const picked = (Array.isArray(media) ? media : [media]).map((item) => item.id);
        const next = [...ids, ...picked.filter((id) => !ids.includes(id))];
        onChange(limit > 0 ? next.slice(0, limit) : next);
    };
    const move = (from: number, to: number) => {
        const next = [...ids];
        next.splice(to, 0, ...next.splice(from, 1));
        onChange(next);
    };

    return (
        <BaseControl __nextHasNoMarginBottom id={controlId} label={fieldLabel(field)} help={field.description}>
            {ids.length > 0 ? (
                <ul className="taw-data-panel__files">
                    {ids.map((id, index) => (
                        <FileItem
                            key={id}
                            id={id}
                            field={field}
                            index={index}
                            count={ids.length}
                            onMove={move}
                            onRemove={() => onChange(ids.filter((other) => other !== id))}
                        />
                    ))}
                </ul>
            ) : null}
            {field.readonly ? null : (
                <MediaUploadCheck fallback={<Fallback />}>
                    <MediaUpload
                        title={field.label ?? field.id}
                        multiple="add"
                        allowedTypes={field.file_types ? [field.file_types] : undefined}
                        onSelect={add}
                        render={({ open }) => (
                            <Button
                                id={controlId}
                                className="taw-data-panel__add"
                                variant="secondary"
                                icon="plus-alt2"
                                disabled={full}
                                onClick={open}
                                __next40pxDefaultSize
                            >
                                {field.button_label ?? __('Add files', 'taw-core')}
                            </Button>
                        )}
                    />
                    {limit > 0 ? (
                        <p className="taw-data-panel__count">
                            {sprintf(
                                /* translators: 1: files chosen, 2: most allowed. */
                                __('%1$d of %2$d', 'taw-core'),
                                ids.length,
                                limit,
                            )}
                        </p>
                    ) : null}
                </MediaUploadCheck>
            )}
        </BaseControl>
    );
}

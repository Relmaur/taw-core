import React from 'react';
import { describe, expect, it } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { editPost, editor, media } from '../tests/wp-globals';
import FieldControl from './FieldControl';
import type { FieldDescriptor } from './types';

const photo = (id: number, extra: Record<string, unknown> = {}) => ({
    id,
    source_url: `https://site.test/photo-${id}.jpg`,
    mime_type: 'image/jpeg',
    title: { raw: `Photo ${id}` },
    media_details: { sizes: { medium: { source_url: `https://site.test/photo-${id}-300.jpg` } } },
    ...extra,
});

function renderField(field: FieldDescriptor, edited: Record<string, unknown>) {
    editor.edited = { meta: { _taw_other: 'kept' }, ...edited };
    return render(<FieldControl field={field} />);
}

describe('image', () => {
    const field: FieldDescriptor = { id: 'cover', type: 'image', label: 'Cover', binding: { meta: '_taw_cover' } };

    it('empty: a drop target that stores the chosen attachment id', () => {
        media.pick = [{ id: 42 }];
        renderField(field, { meta: { _taw_other: 'kept', _taw_cover: 0 } });

        fireEvent.click(screen.getByRole('button', { name: 'Choose an image' }));
        expect(editPost).toHaveBeenCalledWith({ meta: { _taw_other: 'kept', _taw_cover: 42 } });
    });

    it('set: shows the medium size, and Remove stores 0 like the metabox', () => {
        media.records = { 7: photo(7) };
        renderField(field, { meta: { _taw_other: 'kept', _taw_cover: 7 } });

        expect(screen.getByRole('button', { name: 'Replace image' }).querySelector('img')).toHaveAttribute(
            'src',
            'https://site.test/photo-7-300.jpg',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
        expect(editPost).toHaveBeenCalledWith({ meta: { _taw_other: 'kept', _taw_cover: 0 } });
    });

    it('says so when the attachment was deleted', () => {
        media.gone = [9];
        renderField(field, { meta: { _taw_cover: 9 } });
        expect(screen.getByText('Image #9 is no longer in the media library')).toBeInTheDocument();
    });

    it('readonly: no Replace or Remove', () => {
        media.records = { 7: photo(7) };
        renderField({ ...field, readonly: true }, { meta: { _taw_cover: 7 } });
        expect(screen.queryByRole('button', { name: 'Remove' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Replace image' })).toBeDisabled();
    });
});

describe('files', () => {
    const field: FieldDescriptor = {
        id: 'gallery',
        type: 'files',
        label: 'Gallery',
        limit: 3,
        binding: { field: 'taw_gallery' },
    };

    it('lists the files in order, with names or thumbnails', () => {
        media.records = {
            1: photo(1),
            2: {
                id: 2,
                source_url: 'https://site.test/brochure.pdf',
                mime_type: 'application/pdf',
                title: { raw: 'Brochure' },
            },
        };
        renderField(field, { taw_gallery: [1, 2] });

        const items = screen.getAllByRole('listitem');
        expect(items.map((item) => item.textContent)).toEqual(['Photo 1', 'Brochure']);
        expect(items[0].querySelector('img')).toHaveAttribute('src', 'https://site.test/photo-1-300.jpg');
        expect(screen.getByText('2 of 3')).toBeInTheDocument();
    });

    it('adds new picks after the existing ones, skipping duplicates, up to the limit', () => {
        media.pick = [{ id: 2 }, { id: 5 }, { id: 6 }];
        renderField(field, { taw_gallery: [1, 2] });

        fireEvent.click(screen.getByRole('button', { name: 'Add files' }));
        expect(editPost).toHaveBeenCalledWith({ taw_gallery: [1, 2, 5] });
    });

    it('reorders and removes', () => {
        media.records = { 1: photo(1), 2: photo(2) };
        renderField(field, { taw_gallery: [1, 2] });

        fireEvent.click(screen.getAllByRole('button', { name: 'Move down' })[0]);
        expect(editPost).toHaveBeenLastCalledWith({ taw_gallery: [2, 1] });

        fireEvent.click(screen.getByRole('button', { name: 'Remove Photo 2' }));
        expect(editPost).toHaveBeenLastCalledWith({ taw_gallery: [1] });
    });

    it('disables Add at the limit and uses the field’s button label', () => {
        renderField({ ...field, limit: 1, button_label: 'Add a PDF' }, { taw_gallery: [1] });
        expect(screen.getByRole('button', { name: 'Add a PDF' })).toBeDisabled();
    });
});

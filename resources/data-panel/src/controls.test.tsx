import React from 'react';
import { describe, expect, it } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { editPost, editor } from '../tests/wp-globals';
import FieldControl from './FieldControl';
import type { FieldDescriptor } from './types';

const meta = (id: string) => ({ meta: `_taw_${id}` });

function renderField(field: FieldDescriptor, stored: Record<string, unknown> = {}) {
    editor.edited = { meta: { _taw_other: 'kept', ...stored } };
    return render(<FieldControl field={field} />);
}

describe('scalar controls write what the metabox writes', () => {
    it('text: into meta, keeping the other meta values', () => {
        renderField(
            { id: 'author', type: 'text', label: 'Author', required: true, binding: meta('author') },
            { _taw_author: 'Ada' },
        );
        const input = screen.getByLabelText(/Author/);
        expect(input).toHaveValue('Ada');
        expect(screen.getByText('*')).toBeInTheDocument();

        fireEvent.change(input, { target: { value: 'Grace' } });
        expect(editPost).toHaveBeenCalledWith({ meta: { _taw_other: 'kept', _taw_author: 'Grace' } });
    });

    it('url uses a url input; readonly is disabled', () => {
        renderField({ id: 'site', type: 'url', label: 'Site', readonly: true, binding: meta('site') });
        expect(screen.getByLabelText('Site')).toHaveAttribute('type', 'url');
        expect(screen.getByLabelText('Site')).toBeDisabled();
    });

    it('textarea', () => {
        renderField({ id: 'bio', type: 'textarea', label: 'Bio', binding: meta('bio') });
        fireEvent.change(screen.getByLabelText('Bio'), { target: { value: 'Line 1\nLine 2' } });
        expect(editPost).toHaveBeenCalledWith({ meta: { _taw_other: 'kept', _taw_bio: 'Line 1\nLine 2' } });
    });

    it('number: sends a number, or empty', () => {
        renderField({ id: 'year', type: 'number', label: 'Year', binding: meta('year') }, { _taw_year: 1965 });
        const input = screen.getByLabelText('Year');
        expect(input).toHaveValue(1965);
        fireEvent.change(input, { target: { value: '1966' } });
        expect(editPost).toHaveBeenLastCalledWith({ meta: { _taw_other: 'kept', _taw_year: 1966 } });
        fireEvent.change(input, { target: { value: '' } });
        expect(editPost).toHaveBeenLastCalledWith({ meta: { _taw_other: 'kept', _taw_year: '' } });
    });

    it('range: starts at the default, then min', () => {
        renderField({
            id: 'opacity',
            type: 'range',
            label: 'Opacity',
            min: 10,
            max: 90,
            default: 50,
            binding: meta('opacity'),
        });
        expect(screen.getByLabelText('Opacity')).toHaveValue('50');
        fireEvent.change(screen.getByLabelText('Opacity'), { target: { value: '70' } });
        expect(editPost).toHaveBeenCalledWith({ meta: { _taw_other: 'kept', _taw_opacity: 70 } });
    });

    it('select: value => label options, with an explicit empty choice when unset', () => {
        renderField({
            id: 'genre',
            type: 'select',
            label: 'Genre',
            options: { scifi: 'Sci-fi', poetry: 'Poetry' },
            binding: meta('genre'),
        });
        const select = screen.getByLabelText('Genre');
        expect(select).toHaveValue('');
        expect(screen.getAllByRole('option').map((o) => o.textContent)).toEqual(['— Select —', 'Sci-fi', 'Poetry']);
        fireEvent.change(select, { target: { value: 'poetry' } });
        expect(editPost).toHaveBeenCalledWith({ meta: { _taw_other: 'kept', _taw_genre: 'poetry' } });
    });

    it('checkbox: reads "1" or true, writes a boolean', () => {
        renderField(
            { id: 'featured', type: 'checkbox', label: 'Featured', binding: meta('featured') },
            { _taw_featured: '1' },
        );
        const box = screen.getByLabelText('Featured');
        expect(box).toBeChecked();
        fireEvent.click(box);
        expect(editPost).toHaveBeenCalledWith({ meta: { _taw_other: 'kept', _taw_featured: false } });
    });

    it('color: the theme palette or a custom hex; clearing stores empty', () => {
        editor.palette = [{ name: 'Accent', slug: 'accent', color: '#2f5bea' }];
        renderField(
            { id: 'accent', type: 'color', label: 'Accent', binding: meta('accent') },
            { _taw_accent: '#2f5bea' },
        );
        expect(screen.getByRole('button', { name: /Accent \(#2f5bea\)/ })).toBeInTheDocument();
        expect(screen.getByTestId('swatch')).toHaveAttribute('data-color', '#2f5bea');

        fireEvent.change(screen.getByLabelText('custom color'), { target: { value: '#abcdef' } });
        expect(editPost).toHaveBeenLastCalledWith({ meta: { _taw_other: 'kept', _taw_accent: '#abcdef' } });
        fireEvent.change(screen.getByLabelText('custom color'), { target: { value: '' } });
        expect(editPost).toHaveBeenLastCalledWith({ meta: { _taw_other: 'kept', _taw_accent: '' } });
    });

    it('color: empty shows a prompt', () => {
        renderField({ id: 'accent', type: 'color', label: 'Accent', binding: meta('accent') });
        expect(screen.getByRole('button', { name: /Choose a color/ })).toBeInTheDocument();
    });

    it('datepicker: written in the field format, shown readably, clearable', () => {
        renderField(
            { id: 'launch', type: 'datepicker', label: 'Launch', date_format: 'dd/mm/yy', binding: meta('launch') },
            { _taw_launch: '01/02/2026' },
        );
        expect(screen.getByRole('button', { name: 'February 1, 2026' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'pick 5 March 2026' }));
        expect(editPost).toHaveBeenLastCalledWith({ meta: { _taw_other: 'kept', _taw_launch: '05/03/2026' } });
        fireEvent.click(screen.getByRole('button', { name: 'Clear' }));
        expect(editPost).toHaveBeenLastCalledWith({ meta: { _taw_other: 'kept', _taw_launch: '' } });
    });

    it('datepicker: an unsupported format is a text box', () => {
        renderField(
            { id: 'launch', type: 'datepicker', label: 'Launch', date_format: 'MM d, yy', binding: meta('launch') },
            { _taw_launch: 'March 5, 2026' },
        );
        expect(screen.getByLabelText('Launch')).toHaveValue('March 5, 2026');
    });

    it('a top-level REST field binding is edited directly', () => {
        editor.edited = { meta: {}, taw_book_note: 'hi' };
        render(
            <FieldControl
                field={{ id: 'book_note', type: 'text', label: 'Note', binding: { field: 'taw_book_note' } }}
            />,
        );
        expect(screen.getByLabelText('Note')).toHaveValue('hi');
        fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'hey' } });
        expect(editPost).toHaveBeenCalledWith({ taw_book_note: 'hey' });
    });
});

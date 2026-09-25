import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { api, editPost, editor } from '../tests/wp-globals';
import FieldControl from './FieldControl';
import { selectedIds, subtypes, toValue } from './controls/PostSelect';
import type { FieldDescriptor } from './types';

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

/** Let debounced fetches start and their promises settle. */
async function settle() {
    await act(async () => {
        vi.advanceTimersByTime(300);
        await Promise.resolve();
        await Promise.resolve();
    });
}

function renderField(field: FieldDescriptor, edited: Record<string, unknown>, icons = true) {
    editor.settings = { tawDataPanel: { version: 1, postType: 'book', fieldsets: [], warnings: [], icons } };
    editor.edited = { meta: {}, ...edited };
    return render(<FieldControl field={field} />);
}

const svg = (name: string) => `<svg data-icon="${name}"></svg>`;

describe('icon', () => {
    const field: FieldDescriptor = { id: 'badge', type: 'icon', label: 'Badge', binding: { meta: '_taw_badge' } };

    it('shows the stored icon and picks a new one from the search', async () => {
        api.routes = [
            [
                /search=house/,
                [
                    { name: 'house', svg: svg('house') },
                    { name: 'house-plus', svg: svg('house-plus') },
                ],
            ],
            [/search=&/, [{ name: 'star', svg: svg('star') }]],
        ];
        renderField(field, { meta: { _taw_badge: 'house' } });
        await settle();

        const toggle = screen.getByRole('button', { name: 'house' });
        expect(toggle.querySelector('[data-icon="house"]')).not.toBeNull();

        fireEvent.click(screen.getByRole('option', { name: 'star' }));
        expect(editPost).toHaveBeenCalledWith({ meta: { _taw_badge: 'star' } });

        fireEvent.click(screen.getByRole('button', { name: 'Remove house' }));
        expect(editPost).toHaveBeenLastCalledWith({ meta: { _taw_badge: '' } });
    });

    it('explains when the endpoint fails', async () => {
        api.routes = [[/icons/, new Error('404')]];
        renderField(field, { meta: { _taw_badge: '' } });
        await settle();
        expect(screen.getByText('Icons couldn’t be loaded.')).toBeInTheDocument();
    });

    it('says so when the icon picker is off for the site', () => {
        renderField(field, { meta: {} }, false);
        expect(screen.getByText(/Icons aren’t switched on/)).toBeInTheDocument();
        expect(api.calls).toEqual([]);
    });
});

describe('post_select values', () => {
    const single: FieldDescriptor = { id: 'related', type: 'post_select' };
    const multi: FieldDescriptor = { id: 'related', type: 'post_select', multiple: true, max: 2 };

    it('reads what the REST field decodes', () => {
        expect(selectedIds(single, 12)).toEqual([12]);
        expect(selectedIds(single, null)).toEqual([]);
        expect(selectedIds(multi, [3, 4])).toEqual([3, 4]);
        expect(selectedIds(multi, ['5', 0, 'x'])).toEqual([5]);
    });

    it('writes an id or null (single), a capped list (multiple)', () => {
        expect(toValue(single, [8, 9])).toBe(8);
        expect(toValue(single, [])).toBeNull();
        expect(toValue(multi, [1, 2, 3])).toEqual([1, 2]);
    });

    it('turns the field’s post types into search subtypes', () => {
        expect(subtypes(single)).toBe('post');
        expect(subtypes({ ...single, post_type: 'book, page ,' })).toBe('book,page');
    });
});

describe('post_select', () => {
    const field: FieldDescriptor = {
        id: 'related',
        type: 'post_select',
        label: 'Related',
        multiple: true,
        post_type: 'book',
        binding: { field: 'taw_related' },
    };

    it('shows chosen titles, searches published posts, adds, reorders and removes', async () => {
        api.routes = [
            [/include=3%2C4/, [{ id: 4, title: 'Dune &amp; Sons', subtype: 'book' }]],
            [/search=emma/, [{ id: 9, title: 'Emma', subtype: 'book' }]],
        ];
        renderField(field, { taw_related: [3, 4] });
        await settle();

        expect(screen.getByText('Dune & Sons')).toBeInTheDocument();
        expect(screen.getByText('#3 (not published)')).toBeInTheDocument();

        const search = screen.getByRole('searchbox');
        fireEvent.focus(search);
        fireEvent.change(search, { target: { value: 'emma' } });
        await settle();

        const query = api.calls.find((path) => path.includes('search=emma')) ?? '';
        expect(query).toContain('subtype=book');
        expect(query).toContain('exclude=3%2C4');

        fireEvent.click(screen.getByRole('option', { name: /Emma/ }));
        expect(editPost).toHaveBeenLastCalledWith({ taw_related: [3, 4, 9] });

        fireEvent.click(screen.getAllByRole('button', { name: 'Move up' })[1]);
        expect(editPost).toHaveBeenLastCalledWith({ taw_related: [4, 3] });

        fireEvent.click(screen.getByRole('button', { name: 'Remove Dune & Sons' }));
        expect(editPost).toHaveBeenLastCalledWith({ taw_related: [3] });
    });

    it('single: one post, then Change to search again', async () => {
        api.routes = [[/include=5/, [{ id: 5, title: 'Middlemarch', subtype: 'post' }]]];
        renderField({ ...field, multiple: false }, { taw_related: 5 });
        await settle();

        expect(screen.getByText('Middlemarch')).toBeInTheDocument();
        expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Change' }));
        expect(screen.getByRole('searchbox')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Remove Middlemarch' }));
        expect(editPost).toHaveBeenLastCalledWith({ taw_related: null });
    });
});

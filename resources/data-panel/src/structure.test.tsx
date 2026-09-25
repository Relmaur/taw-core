import React from 'react';
import { describe, expect, it } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { dispatchers, editPost, editor } from '../tests/wp-globals';
import Fieldset from './Fieldset';
import DataPanel from './DataPanel';
import { missingRequired } from './required';
import { asRows, move, newRow, rowSummary, syncKeys } from './repeater';
import type { FieldDescriptor, FieldsetDescriptor } from './types';

const fieldset = (fields: FieldDescriptor[]): FieldsetDescriptor => ({
    id: 'book_details',
    title: 'Book details',
    icon: '',
    templates: [],
    tabs: [],
    fields,
    active: true,
    always: true,
});

const dims: FieldDescriptor = {
    id: 'dims',
    type: 'group',
    label: 'Dimensions',
    fields: [
        { id: 'w', type: 'number', label: 'Width', required: true, binding: { meta: '_taw_dims_w' } },
        {
            id: 'unit',
            type: 'text',
            label: 'Unit',
            binding: { meta: '_taw_dims_unit' },
            conditions: [{ field: 'dims_w', operator: '!empty', value: '' }],
        },
    ],
};

const links: FieldDescriptor = {
    id: 'links',
    type: 'repeater',
    label: 'Links',
    max: 3,
    min: 1,
    button_label: 'Add link',
    binding: { field: 'taw_links' },
    fields: [
        { id: 'title', type: 'text', label: 'Title', default: 'New link' },
        { id: 'kind', type: 'select', label: 'Kind', options: { web: 'Website', pdf: 'PDF' } },
        {
            id: 'file',
            type: 'image',
            label: 'File',
            conditions: [{ field: 'kind', operator: '==', value: 'pdf' }],
        },
    ],
};

describe('repeater helpers', () => {
    it('keeps only object rows, builds new rows from defaults, summarises rows', () => {
        expect(asRows([{ a: 1 }, 'x', null, [1]])).toEqual([{ a: 1 }]);
        expect(newRow(links.fields ?? [])).toEqual({ title: 'New link' });
        expect(rowSummary({ title: '', kind: 'pdf' }, links.fields ?? [])).toBe('PDF');
        expect(rowSummary({ title: '  Dune  review ' }, links.fields ?? [])).toBe('Dune review');
        expect(rowSummary({}, links.fields ?? [])).toBe('');
    });

    it('moves items and keeps row keys unless the row count changed elsewhere', () => {
        expect(move(['a', 'b', 'c'], 0, 2)).toEqual(['b', 'c', 'a']);
        expect(move(['a', 'b'], 0, -1)).toEqual(['a', 'b']);
        const keys = ['r1', 'r2'];
        expect(syncKeys(keys, 2)).toBe(keys);
        expect(syncKeys(keys, 3)).toHaveLength(3);
    });
});

describe('group', () => {
    it('shows its sub-fields in one card, bound to their own meta keys, with live conditions', () => {
        editor.edited = { meta: { _taw_dims_w: '' } };
        const { rerender } = render(<Fieldset fieldset={fieldset([dims])} initialOpen />);

        const group = screen.getByRole('group', { name: /Dimensions/ });
        expect(within(group).getByLabelText(/Width/)).toBeInTheDocument();
        expect(within(group).queryByLabelText('Unit')).not.toBeInTheDocument();

        fireEvent.change(within(group).getByLabelText(/Width/), { target: { value: '20' } });
        expect(editPost).toHaveBeenCalledWith({ meta: { _taw_dims_w: 20 } });

        editor.edited = { meta: { _taw_dims_w: 20 } };
        rerender(<Fieldset fieldset={fieldset([dims])} initialOpen />);
        expect(within(group).getByLabelText('Unit')).toBeInTheDocument();
    });

    it('a read-only group makes its sub-fields read-only', () => {
        editor.edited = { meta: { _taw_dims_w: 5 } };
        render(<Fieldset fieldset={fieldset([{ ...dims, readonly: true }])} initialOpen />);
        expect(screen.getByLabelText(/Width/)).toBeDisabled();
    });
});

describe('repeater', () => {
    const rows = [
        { title: 'Site', kind: 'web' },
        { title: 'Brochure', kind: 'pdf', file: 0 },
    ];

    function renderLinks(value: unknown = rows, field: FieldDescriptor = links) {
        editor.edited = { meta: {}, taw_links: value };
        return render(<Fieldset fieldset={fieldset([field])} initialOpen />);
    }

    it('lists rows by their summary, with row conditions from sibling values', () => {
        renderLinks();
        expect(screen.getByRole('button', { name: /Site/, expanded: true })).toBeInTheDocument();
        const toggles = screen.getAllByRole('button', { expanded: true });
        expect(toggles.map((toggle) => toggle.textContent)).toEqual(['1Site', '2Brochure']);
        // Only the PDF row shows its file field.
        expect(screen.getAllByText('Choose an image')).toHaveLength(1);
    });

    it('edits a row value in place', () => {
        renderLinks();
        fireEvent.change(screen.getAllByLabelText('Title')[1], { target: { value: 'Flyer' } });
        expect(editPost).toHaveBeenLastCalledWith({
            taw_links: [
                { title: 'Site', kind: 'web' },
                { title: 'Flyer', kind: 'pdf', file: 0 },
            ],
        });
    });

    it('adds (with defaults), moves and removes rows, within min and max', () => {
        renderLinks();
        fireEvent.click(screen.getByRole('button', { name: 'Add link' }));
        expect(editPost).toHaveBeenLastCalledWith({ taw_links: [...rows, { title: 'New link' }] });

        fireEvent.click(screen.getByRole('button', { name: 'Move Brochure up' }));
        expect(editPost).toHaveBeenLastCalledWith({ taw_links: [rows[1], rows[0]] });

        fireEvent.click(screen.getByRole('button', { name: 'Remove Site' }));
        expect(editPost).toHaveBeenLastCalledWith({ taw_links: [rows[1]] });
        expect(screen.getByText('2 of 3')).toBeInTheDocument();
    });

    it('can’t go past max or below min', () => {
        renderLinks([{ title: 'a' }, { title: 'b' }, { title: 'c' }]);
        expect(screen.getByRole('button', { name: 'Add link' })).toBeDisabled();

        renderLinks([{ title: 'only' }]);
        expect(screen.getByRole('button', { name: 'Remove only' })).toBeDisabled();
    });

    it('collapses rows, and starts collapsed when there are many', () => {
        renderLinks([{ title: 'a' }, { title: 'b' }, { title: 'c' }], { ...links, max: 0 });
        expect(screen.queryByLabelText('Title')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /b$/, expanded: false }));
        expect(screen.getAllByLabelText('Title')).toHaveLength(1);

        fireEvent.click(screen.getByRole('button', { name: 'Expand all' }));
        expect(screen.getAllByLabelText('Title')).toHaveLength(3);
        fireEvent.click(screen.getByRole('button', { name: 'Collapse all' }));
        expect(screen.queryByLabelText('Title')).not.toBeInTheDocument();
    });

    it('nested repeaters edit inside their parent row', () => {
        const nested: FieldDescriptor = {
            ...links,
            min: 0,
            fields: [
                { id: 'title', type: 'text', label: 'Title' },
                { id: 'tags', type: 'repeater', label: 'Tags', fields: [{ id: 'tag', type: 'text', label: 'Tag' }] },
            ],
        };
        renderLinks([{ title: 'a', tags: [{ tag: 'x' }] }], nested);
        fireEvent.change(screen.getByLabelText('Tag'), { target: { value: 'y' } });
        expect(editPost).toHaveBeenLastCalledWith({ taw_links: [{ title: 'a', tags: [{ tag: 'y' }] }] });
    });

    it('read-only: no add, move or remove, and disabled inputs', () => {
        renderLinks(rows, { ...links, readonly: true });
        expect(screen.queryByRole('button', { name: 'Add link' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Remove/ })).not.toBeInTheDocument();
        expect(screen.getAllByLabelText('Title')[0]).toBeDisabled();
    });
});

describe('required fields', () => {
    const author: FieldDescriptor = {
        id: 'author',
        type: 'text',
        label: 'Author',
        required: true,
        binding: { meta: '_taw_author' },
    };
    const sale: FieldDescriptor = { id: 'sale', type: 'checkbox', label: 'On sale', binding: { meta: '_taw_sale' } };
    const price: FieldDescriptor = {
        id: 'price',
        type: 'number',
        label: 'Price',
        required: true,
        binding: { meta: '_taw_price' },
        conditions: [{ field: 'sale', operator: '==', value: '1' }],
    };
    const code: FieldDescriptor = {
        id: 'code',
        type: 'text',
        label: 'Code',
        required: true,
        readonly: true,
        binding: { meta: '_taw_code' },
    };

    it('are missing when empty, shown and editable; group sub-fields count; repeater rows don’t', () => {
        const read = (meta: Record<string, unknown>) => (binding: { meta: string } | { field: string }) =>
            'meta' in binding ? meta[binding.meta] : undefined;
        const sets = [fieldset([author, sale, price, code, dims, links])];

        expect(missingRequired(sets, read({ _taw_author: '', _taw_sale: false })).map((m) => m.label)).toEqual([
            'Author',
            'Width',
        ]);
        expect(
            missingRequired(sets, read({ _taw_author: 'Ada', _taw_sale: true, _taw_dims_w: 3 })).map((m) => m.key),
        ).toEqual(['_taw_price']);
        expect(
            missingRequired(sets, read({ _taw_author: 'Ada', _taw_sale: true, _taw_price: 0, _taw_dims_w: 3 })),
        ).toEqual([]);
    });

    function renderPanel(meta: Record<string, unknown>) {
        editor.settings = {
            tawDataPanel: { version: 1, postType: 'book', fieldsets: [fieldset([author])], warnings: [] },
        };
        editor.edited = { meta };
        return render(<DataPanel />);
    }

    it('lock saving and say which, with a button that opens the panel', () => {
        renderPanel({ _taw_author: '' });
        expect(dispatchers.lockPostSaving).toHaveBeenCalledWith('taw-data-panel');
        const [message, options] = dispatchers.createWarningNotice.mock.lastCall ?? [];
        expect(message).toBe('Fill in the required data before saving: Author.');
        expect(options).toMatchObject({ id: 'taw-data-panel-required', isDismissible: false });
        options.actions[0].onClick();
        expect(dispatchers.enableComplementaryArea).toHaveBeenCalledWith('core', 'taw-data-panel/taw-data-panel');
    });

    it('mark empty required fields once editing has started', () => {
        renderPanel({ _taw_author: '' });
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        cleanup();

        editor.dirty = true;
        renderPanel({ _taw_author: '' });
        expect(screen.getByRole('alert')).toHaveTextContent('Author is required.');
        expect(document.querySelector('[data-field="author"]')).toHaveClass('has-error');
    });

    it('unlock once filled', () => {
        renderPanel({ _taw_author: 'Ada' });
        expect(dispatchers.lockPostSaving).not.toHaveBeenCalled();
        expect(dispatchers.unlockPostSaving).toHaveBeenCalledWith('taw-data-panel');
        expect(dispatchers.removeNotice).toHaveBeenCalledWith('taw-data-panel-required');
    });
});

describe('save errors', () => {
    it('mark the fields a refused save named', () => {
        editor.settings = {
            tawDataPanel: {
                version: 1,
                postType: 'book',
                fieldsets: [
                    fieldset([
                        { id: 'author', type: 'text', label: 'Author', binding: { meta: '_taw_author' } },
                        dims,
                        { id: 'isbn', type: 'text', label: 'ISBN', binding: { meta: '_taw_isbn' } },
                    ]),
                ],
                warnings: [],
            },
        };
        editor.edited = { meta: { _taw_author: 'Ada', _taw_dims_w: 3, _taw_isbn: 'x' } };
        editor.saveError = {
            code: 'taw_data_invalid',
            data: {
                fields: [
                    { field: '_taw_isbn', message: 'ISBN must be 13 digits.' },
                    { field: '_taw_dims_w', message: 'Width is read-only.' },
                ],
            },
        };
        render(<DataPanel />);

        const alerts = screen.getAllByRole('alert').map((alert) => alert.textContent);
        expect(alerts).toEqual(['Width is read-only.', 'ISBN must be 13 digits.']);
        expect(document.querySelector('[data-field="isbn"]')).toHaveClass('has-error');
        expect(document.querySelector('[data-field="author"]')).not.toHaveClass('has-error');
    });

    it('ignore other save failures', () => {
        editor.settings = {
            tawDataPanel: { version: 1, postType: 'book', fieldsets: [fieldset([dims])], warnings: [] },
        };
        editor.edited = { meta: { _taw_dims_w: 3 } };
        editor.saveError = { code: 'rest_cannot_edit', data: { status: 403 } };
        render(<DataPanel />);
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
});

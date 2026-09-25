import React from 'react';
import { describe, expect, it } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { editor } from '../tests/wp-globals';
import Fieldset from './Fieldset';
import type { FieldsetDescriptor } from './types';

const base: FieldsetDescriptor = {
    id: 'deal',
    title: 'Deal',
    icon: '',
    templates: [],
    tabs: [],
    active: true,
    always: true,
    fields: [
        { id: 'has_sale', type: 'checkbox', label: 'On sale', binding: { meta: '_taw_has_sale' } },
        {
            id: 'sale_price',
            type: 'number',
            label: 'Sale price',
            binding: { meta: '_taw_sale_price' },
            conditions: [{ field: 'has_sale', operator: '==', value: '1' }],
        },
        { id: 'notes', type: 'textarea', label: 'Notes', binding: { meta: '_taw_notes' } },
    ],
};

describe('a fieldset', () => {
    it('hides fields whose conditions are not met, live', () => {
        editor.edited = { meta: { _taw_has_sale: false } };
        const { rerender } = render(<Fieldset fieldset={base} initialOpen />);
        expect(screen.queryByLabelText('Sale price')).not.toBeInTheDocument();

        editor.edited = { meta: { _taw_has_sale: true } };
        rerender(<Fieldset key="on" fieldset={base} initialOpen />);
        expect(screen.getByLabelText('Sale price')).toBeInTheDocument();
    });

    it('puts fields in their tabs and keeps unclaimed ones above', () => {
        editor.edited = { meta: { _taw_has_sale: true } };
        render(
            <Fieldset
                fieldset={{ ...base, tabs: [{ label: 'Pricing', icon: '', fields: ['has_sale', 'sale_price'] }] }}
                initialOpen
            />,
        );

        const pricing = screen.getByRole('tabpanel', { name: 'Pricing' });
        expect(within(pricing).getByLabelText('On sale')).toBeInTheDocument();
        expect(within(pricing).getByLabelText('Sale price')).toBeInTheDocument();
        expect(within(pricing).queryByLabelText('Notes')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Notes')).toBeInTheDocument();
    });

    it('says which types are not in the panel yet', () => {
        render(<Fieldset fieldset={{ ...base, fields: [{ id: 'spot', type: 'map', label: 'Spot' }] }} initialOpen />);
        expect(screen.getByRole('note')).toHaveTextContent('Spot (map) can’t be edited in the panel yet.');
    });
});

import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { editor } from '../tests/wp-globals';
import DataPanel from './DataPanel';
import type { FieldsetDescriptor, PanelDescriptor } from './types';

const fieldset = (over: Partial<FieldsetDescriptor>): FieldsetDescriptor => ({
    id: 'book_details',
    title: 'Book details',
    icon: 'dashicons-book',
    templates: [],
    tabs: [],
    fields: [{ id: 'book_author', type: 'text', label: 'Author', binding: { meta: '_taw_book_author' } }],
    active: true,
    always: true,
    ...over,
});

const panel = (fieldsets: FieldsetDescriptor[], warnings: string[] = []): PanelDescriptor => ({
    version: 1,
    postType: 'book',
    fieldsets,
    warnings,
});

describe('the TAW Data sidebar', () => {
    beforeEach(() => {
        editor.edited = { meta: {}, template: '' };
        editor.saved = { template: '' };
    });

    it('renders nothing for a post without panel fieldsets', () => {
        const { container } = render(<DataPanel />);
        expect(container).toBeEmptyDOMElement();

        editor.settings = { tawDataPanel: panel([]) };
        expect(render(<DataPanel />).container).toBeEmptyDOMElement();
    });

    it('adds a sidebar and a ⋮ menu entry with each fieldset', () => {
        editor.settings = {
            tawDataPanel: panel([fieldset({}), fieldset({ id: 'extra', title: 'Extra', fields: [] })]),
        };
        render(<DataPanel />);

        expect(screen.getByRole('complementary', { name: 'TAW Data' })).toBeInTheDocument();
        expect(screen.getByTestId('more-menu-item')).toHaveTextContent('TAW Data');
        expect(screen.getByRole('region', { name: 'Book details' })).toBeInTheDocument();
        expect(screen.getByRole('region', { name: 'Extra' })).toBeInTheDocument();
        expect(screen.getByLabelText('Author')).toBeInTheDocument();
    });

    it('shows resolver warnings', () => {
        editor.settings = {
            tawDataPanel: panel([fieldset({})], ['TAW_DATA_UI must be one of panel, metabox; ignoring "x".']),
        };
        render(<DataPanel />);
        expect(screen.getByRole('note')).toHaveTextContent('TAW_DATA_UI must be one of');
    });

    it('follows a template change made in the editor', () => {
        const about = fieldset({
            id: 'about',
            title: 'About hero',
            templates: ['page-about.php'],
            active: false,
            always: false,
        });
        editor.settings = { tawDataPanel: panel([about]) };

        const { rerender } = render(<DataPanel />);
        expect(screen.queryByRole('region', { name: 'About hero' })).not.toBeInTheDocument();
        expect(screen.getByText('No data fields apply to this template.')).toBeInTheDocument();

        editor.edited = { ...editor.edited, template: 'page-about' };
        rerender(<DataPanel key="changed" />);
        expect(screen.getByRole('region', { name: 'About hero' })).toBeInTheDocument();
    });

    it('registers itself as a plugin', async () => {
        vi.resetModules();
        await import('./index');
        expect(window.wp.plugins.registerPlugin).toHaveBeenCalledWith(
            'taw-data-panel',
            expect.objectContaining({ icon: 'database' }),
        );
    });
});

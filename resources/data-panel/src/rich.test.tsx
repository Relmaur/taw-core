import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { blockEditorProps, blocksApi, editPost, editor } from '../tests/wp-globals';
import FieldControl from './FieldControl';
import { allowedBlocks, DEFAULT_BLOCKS, excerpt, TEENY_BLOCKS, toBlocks, toHtml } from './rich/html';
import type { BlockInstance } from '@wordpress/blocks';
import type { FieldDescriptor } from './types';

const block = (name: string, attributes: Record<string, unknown> = {}, innerBlocks: BlockInstance[] = []) =>
    ({ clientId: name, name, attributes, innerBlocks }) as BlockInstance;

describe('stored HTML ↔ blocks', () => {
    it('loads through wpautop, then the "Convert to blocks" handler', () => {
        const converted = [block('core/paragraph', { content: 'One' })];
        blocksApi.rawHandler.mockReturnValue(converted);

        expect(toBlocks('One\n\nTwo')).toBe(converted);
        expect(blocksApi.rawHandler).toHaveBeenCalledWith({ HTML: '<p>One</p>\n<p>Two</p>' });
    });

    it('starts an empty value with one empty paragraph', () => {
        expect(toBlocks('  ')).toEqual([expect.objectContaining({ name: 'core/paragraph' })]);
        expect(blocksApi.rawHandler).not.toHaveBeenCalled();
    });

    it('saves plain HTML: every block delimiter removed, nested ones too, then removep', () => {
        blocksApi.serialize.mockReturnValue(
            [
                '<!-- wp:heading {"level":3} -->',
                '<h3 class="wp-block-heading">Title</h3>',
                '<!-- /wp:heading -->',
                '',
                '<!-- wp:list -->',
                '<ul class="wp-block-list"><!-- wp:list-item -->',
                '<li>A &lt;!-- not a delimiter --&gt;</li>',
                '<!-- /wp:list-item --></ul>',
                '<!-- /wp:list -->',
                '',
                '<!-- wp:separator /-->',
                '',
                '<!-- wp:taw-gutenberg/callout {"kind":"note","text":"a \\u002d\\u002d\\u003e b"} -->',
                '<div class="callout">x</div>',
                '<!-- /wp:taw-gutenberg/callout -->',
                '',
                '<!-- wp:html -->',
                '<!-- a real HTML comment -->',
                '<!-- /wp:html -->',
            ].join('\n'),
        );

        expect(toHtml([block('core/heading')])).toBe(
            [
                '<h3 class="wp-block-heading">Title</h3>',
                '',
                '<ul class="wp-block-list"><li>A &lt;!-- not a delimiter --&gt;</li></ul>',
                '',
                '',
                '<div class="callout">x</div>',
                '',
                '<!-- a real HTML comment -->',
            ].join('\n'),
        );
    });

    it('keeps list items one per line', () => {
        blocksApi.serialize.mockReturnValue(
            '<!-- wp:list -->\n<ul class="wp-block-list"><!-- wp:list-item -->\n<li>One</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>Two</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->',
        );
        expect(toHtml([block('core/list')])).toBe('<ul class="wp-block-list"><li>One</li>\n<li>Two</li></ul>');
    });

    it('drops empty paragraphs, so an untouched empty editor saves ""', () => {
        blocksApi.serialize.mockImplementation((blocks: unknown[]) => (blocks.length ? 'kept' : ''));
        expect(toHtml([block('core/paragraph', { content: '' })])).toBe('');
        expect(blocksApi.serialize).toHaveBeenLastCalledWith([]);
    });

    it('offers the default set, teeny text-only, no media without media_buttons, or the field’s own list', () => {
        const field: FieldDescriptor = { id: 'body', type: 'wysiwyg' };
        expect(allowedBlocks(field)).toEqual(DEFAULT_BLOCKS);
        expect(allowedBlocks({ ...field, teeny: true })).toEqual(TEENY_BLOCKS);
        expect(allowedBlocks({ ...field, media_buttons: false })).not.toContain('core/image');
        expect(allowedBlocks({ ...field, blocks: ['core/paragraph', 'core/code'] })).toEqual([
            'core/paragraph',
            'core/code',
        ]);
    });

    it('previews as plain text, word-limited', () => {
        expect(excerpt('<h2>Hi</h2><p>there <strong>you</strong></p><ul><li>a</li><li>b</li></ul>')).toBe(
            'Hi there you a b',
        );
        expect(excerpt('one two three', 2)).toBe('one two…');
        expect(excerpt('')).toBe('');
    });
});

describe('wysiwyg control', () => {
    const field: FieldDescriptor = { id: 'body', type: 'wysiwyg', label: 'Body', binding: { meta: '_taw_body' } };

    function open(stored: string) {
        editor.edited = { meta: { _taw_body: stored } };
        render(<FieldControl field={field} />);
        fireEvent.click(screen.getByRole('button', { name: stored ? 'Edit' : 'Write' }));
    }

    it('shows a preview and opens the editor with the field’s blocks', () => {
        blocksApi.rawHandler.mockReturnValue([block('core/paragraph', { content: 'Hello' })]);
        open('<p>Hello <em>world</em></p>');

        expect(screen.getByText('Hello world')).toBeInTheDocument();
        expect(screen.getByRole('dialog', { name: 'Body' })).toBeInTheDocument();
        expect((blockEditorProps.current?.settings as Record<string, unknown>).allowedBlockTypes).toEqual(
            DEFAULT_BLOCKS,
        );
    });

    it('opening and applying without a change writes nothing', () => {
        blocksApi.serialize.mockReturnValue('<p>Hello</p>');
        open('Hello');
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));

        expect(editPost).not.toHaveBeenCalled();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('applies an edit in wp_editor()’s format (no <p>)', () => {
        blocksApi.serialize.mockImplementation((blocks: unknown[]) =>
            (blocks as BlockInstance[])
                .map((b) => `<!-- wp:paragraph -->\n<p>${String(b.attributes.content)}</p>\n<!-- /wp:paragraph -->`)
                .join('\n\n'),
        );
        blocksApi.rawHandler.mockReturnValue([block('core/paragraph', { content: 'Hello' })]);
        open('Hello');

        act(() =>
            (blockEditorProps.current?.onChange as (b: BlockInstance[]) => void)([
                block('core/paragraph', { content: 'Hi' }),
            ]),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));

        expect(editPost).toHaveBeenCalledWith({ meta: { _taw_body: 'Hi' } });
    });

    it('asks before discarding an edit', () => {
        blocksApi.serialize.mockImplementation((blocks: unknown[]) =>
            String((blocks as BlockInstance[])[0]?.attributes.content ?? ''),
        );
        blocksApi.rawHandler.mockReturnValue([block('core/paragraph', { content: 'Hello' })]);
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        open('Hello');

        act(() =>
            (blockEditorProps.current?.onInput as (b: BlockInstance[]) => void)([
                block('core/paragraph', { content: 'Changed' }),
            ]),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(confirm).toHaveBeenCalled();
        expect(screen.getByRole('dialog')).toBeInTheDocument();

        confirm.mockReturnValue(true);
        fireEvent.click(screen.getByRole('button', { name: 'Close dialog' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(editPost).not.toHaveBeenCalled();
        confirm.mockRestore();
    });

    it('readonly: no editing', () => {
        editor.edited = { meta: { _taw_body: '<p>Fixed</p>' } };
        render(<FieldControl field={{ ...field, readonly: true }} />);
        expect(screen.getByRole('button', { name: 'Edit' })).toBeDisabled();
    });
});

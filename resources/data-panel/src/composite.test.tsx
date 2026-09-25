import React from 'react';
import { describe, expect, it } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { editPost, editor } from '../tests/wp-globals';
import FieldControl from './FieldControl';
import { hubspotConfig, linkValue, segments } from './controls/CompositeControls';
import { isEmptyValue } from './required';
import type { FieldDescriptor } from './types';

function renderField(field: FieldDescriptor, stored: Record<string, unknown>) {
    editor.edited = { meta: stored };
    return render(<FieldControl field={field} />);
}

describe('gradient_text', () => {
    const field: FieldDescriptor = {
        id: 'headline',
        type: 'gradient_text',
        label: 'Headline',
        binding: { meta: '_taw_headline' },
    };
    const stored = JSON.stringify([
        { text: 'Build ', highlighted: false },
        { text: 'faster', highlighted: true },
    ]);

    it('reads the stored JSON, tolerating what hand-built payloads send', () => {
        expect(segments(stored)).toEqual([
            { text: 'Build ', highlighted: false },
            { text: 'faster', highlighted: true },
        ]);
        expect(segments('[{"text":"a","highlighted":"1"}]')).toEqual([{ text: 'a', highlighted: true }]);
        expect(segments('not json')).toEqual([]);
        expect(segments(undefined)).toEqual([]);
    });

    it('edits, highlights, adds and removes segments as a JSON string, keeping spaces', () => {
        renderField(field, { _taw_headline: stored });

        fireEvent.change(screen.getByLabelText('Segment 1'), { target: { value: 'Ship ' } });
        expect(editPost).toHaveBeenLastCalledWith({
            meta: { _taw_headline: '[{"text":"Ship ","highlighted":false},{"text":"faster","highlighted":true}]' },
        });

        fireEvent.click(screen.getAllByLabelText('Highlighted')[0]);
        expect(JSON.parse(editPost.mock.lastCall?.[0].meta._taw_headline)[0].highlighted).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'Add segment' }));
        expect(JSON.parse(editPost.mock.lastCall?.[0].meta._taw_headline)).toHaveLength(3);

        fireEvent.click(screen.getByRole('button', { name: 'Remove segment 1' }));
        expect(JSON.parse(editPost.mock.lastCall?.[0].meta._taw_headline)).toEqual([
            { text: 'faster', highlighted: true },
        ]);
    });
});

describe('hubspot_form', () => {
    const field: FieldDescriptor = { id: 'form', type: 'hubspot_form', label: 'Form', binding: { meta: '_taw_form' } };

    it('defaults the region to na1', () => {
        expect(hubspotConfig('')).toEqual({ portal_id: '', form_id: '', region: 'na1' });
        expect(hubspotConfig({ portal_id: 123, form_id: 'abc', region: 'eu1' })).toEqual({
            portal_id: '123',
            form_id: 'abc',
            region: 'eu1',
        });
    });

    it('writes all three keys as one JSON string', () => {
        renderField(field, { _taw_form: '{"portal_id":"1","form_id":"f","region":"na1"}' });
        fireEvent.change(screen.getByLabelText('Form ID'), { target: { value: 'g' } });
        expect(editPost).toHaveBeenCalledWith({
            meta: { _taw_form: '{"portal_id":"1","form_id":"g","region":"na1"}' },
        });
    });
});

describe('link', () => {
    const field: FieldDescriptor = { id: 'cta', type: 'link', label: 'Button', binding: { field: 'taw_cta' } };

    it('reads the REST object, a repeater row string, or nothing', () => {
        expect(linkValue({ url: 'https://a.test', label: 'Go', new_tab: true })).toEqual({
            url: 'https://a.test',
            label: 'Go',
            new_tab: true,
        });
        expect(linkValue('{"url":"https://a.test","label":"","new_tab":"1"}')).toEqual({
            url: 'https://a.test',
            label: '',
            new_tab: true,
        });
        expect(linkValue(null)).toEqual({ url: '', label: '', new_tab: false });
    });

    it('edits the url, text and new tab as one object', () => {
        editor.edited = { meta: {}, taw_cta: { url: 'https://a.test', label: 'Go', new_tab: false } };
        render(<FieldControl field={field} />);
        fireEvent.change(screen.getByLabelText('Link text'), { target: { value: 'Read more' } });
        expect(editPost).toHaveBeenLastCalledWith({
            taw_cta: { url: 'https://a.test', label: 'Read more', new_tab: false },
        });
        fireEvent.click(screen.getByLabelText('Open in a new tab'));
        expect(editPost).toHaveBeenLastCalledWith({ taw_cta: { url: 'https://a.test', label: 'Go', new_tab: true } });
    });

    it('counts as empty without a url, for required fields', () => {
        expect(isEmptyValue({ url: '', label: 'Text only', new_tab: true })).toBe(true);
        expect(isEmptyValue({ url: 'https://a.test', label: '', new_tab: false })).toBe(false);
        expect(isEmptyValue(null)).toBe(true);
    });
});

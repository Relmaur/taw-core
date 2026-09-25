import { describe, expect, it } from 'vitest';
import { asPosted, conditionsMet } from './conditions';
import { formatDate, isSupportedFormat, parseDate } from './dates';
import { isVisible, templateSlug } from './templates';
import type { FieldsetDescriptor } from './types';

describe('conditions (same rules as the metabox and the server)', () => {
    it('normalises values the way a form posts them', () => {
        expect(asPosted(true)).toBe('1');
        expect(asPosted(false)).toBe('');
        expect(asPosted(null)).toBe('');
        expect(asPosted(3)).toBe('3');
    });

    it('supports every operator, ANDed', () => {
        const values = { kind: 'ebook-pdf', flag: true, none: '', zero: '0' };
        expect(conditionsMet([{ field: 'kind', operator: 'contains', value: 'pdf' }], values)).toBe(true);
        expect(conditionsMet([{ field: 'kind', operator: '!=', value: 'print' }], values)).toBe(true);
        expect(conditionsMet([{ field: 'flag', operator: '==', value: '1' }], values)).toBe(true);
        expect(conditionsMet([{ field: 'none', operator: 'empty', value: null }], values)).toBe(true);
        expect(conditionsMet([{ field: 'zero', operator: 'empty', value: null }], values), "PHP empty('0')").toBe(true);
        expect(
            conditionsMet(
                [
                    { field: 'kind', operator: '!empty', value: null },
                    { field: 'none', operator: '!empty', value: null },
                ],
                values,
            ),
        ).toBe(false);
        expect(conditionsMet(undefined, values)).toBe(true);
    });
});

describe('template visibility', () => {
    const fieldset = (over: Partial<FieldsetDescriptor>): FieldsetDescriptor => ({
        id: 'x',
        title: 'X',
        icon: '',
        templates: [],
        tabs: [],
        fields: [],
        active: true,
        always: true,
        ...over,
    });

    it('trusts the server for the saved template', () => {
        expect(isVisible(fieldset({ templates: ['page-about.php'], active: false, always: false }), 'a', 'a')).toBe(
            false,
        );
        expect(isVisible(fieldset({ active: true }), 'other', '')).toBe(true);
    });

    it('re-checks only the template when it changes', () => {
        const about = fieldset({ templates: ['page-about.php'], active: false, always: false });
        expect(isVisible(about, 'page-about', '')).toBe(true);
        expect(isVisible(about, 'page-about.php', '')).toBe(true);
        expect(isVisible(about, 'page-contact', '')).toBe(false);
        expect(isVisible({ ...about, active: true }, '', 'page-about'), 'back to the default template').toBe(false);
        expect(isVisible({ ...about, always: true }, 'page-contact', 'page-about'), 'applies by post type anyway').toBe(
            true,
        );
        expect(templateSlug('page-about.php')).toBe('page-about');
    });
});

describe('dates in the metabox formats', () => {
    it('writes and reads yy-mm-dd and friends', () => {
        const date = new Date(2026, 2, 5);
        expect(formatDate(date, 'yy-mm-dd')).toBe('2026-03-05');
        expect(formatDate(date, 'dd/mm/yy')).toBe('05/03/2026');
        expect(formatDate(date, 'd.m.yy')).toBe('5.3.2026');
        expect(parseDate('05/03/2026', 'dd/mm/yy')?.getMonth()).toBe(2);
        expect(parseDate('2026-02-30', 'yy-mm-dd')).toBeNull();
        expect(parseDate('garbage', 'yy-mm-dd')).toBeNull();
    });

    it('knows which formats it can handle', () => {
        expect(isSupportedFormat('yy-mm-dd')).toBe(true);
        expect(isSupportedFormat('dd/mm/yy')).toBe(true);
        expect(isSupportedFormat('MM d, yy')).toBe(false);
        expect(isSupportedFormat('mm/dd')).toBe(false);
    });
});

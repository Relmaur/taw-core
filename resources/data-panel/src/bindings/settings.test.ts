import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { cleanAttribute, cleanClasses, cleanColor, isAttribute, KEY, readSettings, withSetting } from './settings';

// Vitest runs from resources/data-panel (jsdom has no file URLs).
const fixture = JSON.parse(
    readFileSync(resolve(process.cwd(), '../../tests/fixtures/block-settings.json'), 'utf8'),
) as {
    palette: Record<string, string>;
    classes: { in: string; out: string[] }[];
    colors: { in: string; custom?: boolean; out?: string; dropped?: string }[];
    attributeNames: { in: string; ok: boolean }[];
    attributeValues: { name: string; in: string; out?: string; dropped?: string }[];
    settings: { in: unknown; out: object }[];
};

describe('block settings fixture (ADR-0016), shared with PHP', () => {
    it.each(fixture.classes)('classes: $in', ({ in: input, out }) => {
        expect(cleanClasses(input)).toStrictEqual(out);
    });

    it.each(fixture.colors)('color: $in', ({ in: input, custom, out, dropped }) => {
        expect(cleanColor(input, fixture.palette, custom ?? true)).toStrictEqual({
            value: out ?? null,
            dropped: dropped ?? null,
        });
    });

    it.each(fixture.attributeNames)('attribute name: $in', ({ in: input, ok }) => {
        expect(isAttribute(input)).toBe(ok);
    });

    it.each(fixture.attributeValues)('attribute value: $name $in', ({ name, in: input, out, dropped }) => {
        expect(cleanAttribute(name, input)).toStrictEqual({ value: out ?? null, dropped: dropped ?? null });
    });

    it.each(fixture.settings)('settings shape #%#', ({ in: input, out }) => {
        expect(readSettings(input)).toStrictEqual(out);
    });
});

describe('writing settings', () => {
    it('sets, replaces and removes one setting, keeping the rest of the metadata', () => {
        let metadata: Record<string, unknown> = { name: 'Card' };
        metadata = withSetting(metadata, 'classes', "@if(@stock > 0, 'in', 'out')");
        metadata = withSetting(metadata, 'data-genre', '@genre');
        metadata = withSetting(metadata, 'background', 'accent');
        expect(metadata).toStrictEqual({
            name: 'Card',
            [KEY]: {
                classes: "@if(@stock > 0, 'in', 'out')",
                background: 'accent',
                attributes: { 'data-genre': '@genre' },
            },
        });

        metadata = withSetting(metadata, 'data-genre', '  ');
        metadata = withSetting(metadata, 'classes', '');
        expect(metadata).toStrictEqual({ name: 'Card', [KEY]: { background: 'accent' } });

        expect(withSetting(metadata, 'background', '')).toStrictEqual({ name: 'Card' });
    });

    it('refuses attribute names outside the allow-list', () => {
        expect(withSetting({}, 'onclick', 'alert(1)')).toStrictEqual({});
        expect(withSetting({}, 'style', 'x')).toStrictEqual({});
    });
});

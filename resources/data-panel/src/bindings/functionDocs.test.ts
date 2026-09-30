import { describe, expect, it } from 'vitest';
import { registerFunctions, SIGNATURES } from './expression';
import { didYouMean, functionDocs } from './functionDocs';

describe('the function picker (ADR-0015)', () => {
    it('documents every built-in function, grouped', () => {
        const docs = functionDocs();
        expect(docs.map((d) => d.name).sort()).toStrictEqual(Object.keys(SIGNATURES).sort());
        expect(docs[0]).toMatchObject({ name: 'if', group: 'logic', usage: 'if(test, then, else?)' });
        for (const doc of docs) {
            expect(doc.example).toMatch(/@/);
            expect(doc.description).not.toBe('');
        }
    });

    it('lists the site’s own functions under This site', () => {
        registerFunctions({
            with_tax: { params: ['any', 'any'], required: 1, label: 'Price with tax', description: '' },
        });
        expect(functionDocs().at(-1)).toStrictEqual({
            name: 'with_tax',
            group: 'site',
            usage: 'with_tax(x1, x2?)',
            description: 'Price with tax',
            example: '@with_tax(…)',
        });
        registerFunctions([]);
    });

    it('suggests the closest function for a typo', () => {
        expect(didYouMean('rond')).toBe('round');
        expect(didYouMean('currancy')).toBe('currency');
        expect(didYouMean('JOIN')).toBe('join');
        expect(didYouMean('round')).toBeNull();
        expect(didYouMean('somethingelse')).toBeNull();
    });
});

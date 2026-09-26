import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { conditionErrors, OPERATORS, readCondition, ruleCount, withOperator } from './conditions';

// Vitest runs from resources/data-panel; jsdom has no file URLs (see expression.test.ts).
const fixture = JSON.parse(readFileSync(resolve(process.cwd(), '../../tests/fixtures/conditions.json'), 'utf8')) as {
    shape: { name: string; condition: unknown; errors: { code: string; path: string }[] }[];
    evaluate: { rule?: { op: string } }[];
};

describe('conditionErrors (tests/fixtures/conditions.json, shared with PHP)', () => {
    it.each(fixture.shape.map((c) => [c.name, c] as const))('%s', (_name, c) => {
        expect(conditionErrors(c.condition)).toEqual(c.errors);
    });
});

describe('operators', () => {
    it('match the PHP list (every evaluate case uses a known one)', () => {
        const used = new Set(fixture.evaluate.map((c) => c.rule?.op).filter(Boolean));
        expect([...used].sort()).toEqual(Object.keys(OPERATORS).sort());
    });

    it('reshape `to` when the arity changes', () => {
        expect(withOperator({ value: '@a', op: 'equals', to: 'x' }, 'empty')).toEqual({ value: '@a', op: 'empty' });
        expect(withOperator({ value: '@a', op: 'equals', to: 'x' }, 'in')).toEqual({
            value: '@a',
            op: 'in',
            to: ['x'],
        });
        expect(withOperator({ value: '@a', op: 'equals', to: 5 }, 'between')).toEqual({
            value: '@a',
            op: 'between',
            to: [5, ''],
        });
        expect(withOperator({ value: '@a', op: 'in', to: ['x', 'y'] }, 'contains')).toEqual({
            value: '@a',
            op: 'contains',
            to: 'x',
        });
        expect(withOperator({ value: '@a', op: 'empty' }, 'equals')).toEqual({ value: '@a', op: 'equals', to: '' });
    });
});

describe('helpers', () => {
    it('reads stored conditions', () => {
        expect(readCondition({ rules: [] })).toEqual({ rules: [] });
        expect(readCondition('x')).toBeNull();
        expect(readCondition({ match: 'all' })).toBeNull();
    });

    it('counts rules, not groups', () => {
        expect(
            ruleCount({
                rules: [
                    { value: '@a', op: 'empty' },
                    {
                        rules: [
                            { value: '@b', op: 'empty' },
                            { value: '@c', op: 'empty' },
                        ],
                    },
                ],
            }),
        ).toBe(3);
    });
});

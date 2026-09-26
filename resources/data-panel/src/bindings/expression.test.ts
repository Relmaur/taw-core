import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { completionAt, MAX_LENGTH, parseExpression, type Parsed } from './expression';

interface Case extends Parsed {
    name: string;
    expr: string;
}

// The grammar spec, shared with the PHP parser (ExpressionParserTest).
const fixture = JSON.parse(
    // Vitest runs from resources/data-panel.
    readFileSync(resolve(process.cwd(), '../../tests/fixtures/expressions.json'), 'utf8'),
) as { cases: Case[] };

describe('the expression grammar', () => {
    it.each(fixture.cases.map((c) => [c.name, c] as const))('%s', (_name, c) => {
        expect(parseExpression(c.expr)).toStrictEqual({ parts: c.parts, errors: c.errors });
    });

    it('refuses input over the limit, counted in characters', () => {
        expect(parseExpression('a'.repeat(MAX_LENGTH + 1))).toStrictEqual({
            parts: [],
            errors: [{ code: 'too_long', at: 0 }],
        });
        expect(parseExpression('é'.repeat(MAX_LENGTH)).parts).toHaveLength(1);
    });
});

describe('completion at the caret', () => {
    it('completes names after @, namespaces included', () => {
        expect(completionAt('Published on @')).toStrictEqual({ kind: 'name', prefix: '', start: 14 });
        expect(completionAt('Published on @bo')).toStrictEqual({ kind: 'name', prefix: 'bo', start: 14 });
        expect(completionAt('@post.')).toStrictEqual({ kind: 'name', prefix: 'post.', start: 1 });
        expect(completionAt('@post.ti')).toStrictEqual({ kind: 'name', prefix: 'post.ti', start: 1 });
    });

    it('completes functions after a name and a dot', () => {
        expect(completionAt('@book_year.')).toStrictEqual({ kind: 'function', prefix: '', start: 11 });
        expect(completionAt('@post.date.fo')).toStrictEqual({ kind: 'function', prefix: 'fo', start: 11 });
        expect(completionAt("@post.date.format('Y').up")).toStrictEqual({ kind: 'function', prefix: 'up', start: 23 });
    });

    it('completes nothing in plain text or e-mails', () => {
        expect(completionAt('Just text')).toBeNull();
        expect(completionAt('hi@exa')).toBeNull();
        expect(completionAt('@book_year and more')).toBeNull();
    });
});

import { describe, expect, it } from 'vitest';
import { changeLines, lineDiff } from './diff';

describe('the approval card’s diff', () => {
    it('shows screen 01’s edit: context, removed, added, context', () => {
        expect(
            lineDiff('.hero__title {\n  margin-bottom: 3rem;\n}', '.hero__title {\n  margin-bottom: 1.5rem;\n}'),
        ).toEqual([
            { kind: ' ', text: '.hero__title {' },
            { kind: '-', text: '  margin-bottom: 3rem;' },
            { kind: '+', text: '  margin-bottom: 1.5rem;' },
            { kind: ' ', text: '}' },
        ]);
    });

    it('handles additions, removals and empty sides', () => {
        expect(lineDiff('a\nc', 'a\nb\nc')).toEqual([
            { kind: ' ', text: 'a' },
            { kind: '+', text: 'b' },
            { kind: ' ', text: 'c' },
        ]);
        expect(lineDiff('a\n', '')).toEqual([{ kind: '-', text: 'a' }]);
        expect(lineDiff('', '')).toEqual([]);
    });

    it('doesn’t align huge changes, it lists them', () => {
        const big = Array.from({ length: 401 }, (_, i) => `l${i}`).join('\n');
        const lines = lineDiff(big, 'x');
        expect(lines.filter((l) => l.kind === '-')).toHaveLength(401);
        expect(lines.at(-1)).toEqual({ kind: '+', text: 'x' });
    });

    it('shows a new file as added, and several edits with a gap between', () => {
        expect(changeLines({ file: 'a.css', content: 'one\ntwo\n' })).toEqual([
            { kind: '+', text: 'one' },
            { kind: '+', text: 'two' },
        ]);
        expect(
            changeLines({
                edits: [
                    { old: 'a', new: 'b' },
                    { old: 'c', new: 'd' },
                ],
            }).map((l) => l.kind + l.text),
        ).toEqual(['-a', '+b', ' …', '-c', '+d']);
        expect(changeLines({ command: 'ls' })).toEqual([]);
    });
});

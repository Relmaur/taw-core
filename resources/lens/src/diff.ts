/**
 * Line diffs for the approval card (component 01: removed `#FDEEEF`/`#9F2430`, added `#EAF6EF`/`#1C6B3E`).
 * An Edit's old and new strings are short, so a plain longest-common-subsequence diff is enough; anything
 * bigger is shown as removed-then-added rather than spending the page's time on it.
 */
import type { ApprovalInput } from './chatModel';

export interface DiffLine {
    kind: ' ' | '-' | '+';
    text: string;
}

/** Above this many lines on either side, no alignment: all removed, then all added. */
const LCS_MAX = 400;

/** Lines shown before the rest is folded into "… N more lines". */
export const SHOWN_MAX = 200;

const lines = (text: string): string[] => (text === '' ? [] : text.replace(/\n$/, '').split('\n'));

export function lineDiff(before: string, after: string): DiffLine[] {
    const a = lines(before);
    const b = lines(after);
    if (a.length > LCS_MAX || b.length > LCS_MAX) {
        return [...a.map((text) => ({ kind: '-' as const, text })), ...b.map((text) => ({ kind: '+' as const, text }))];
    }
    // lengths[i][j]: the longest common run of a[i..] and b[j..].
    const lengths = Array.from({ length: a.length + 1 }, () => new Array<number>(b.length + 1).fill(0));
    for (let i = a.length - 1; i >= 0; i--) {
        for (let j = b.length - 1; j >= 0; j--) {
            lengths[i][j] = a[i] === b[j] ? lengths[i + 1][j + 1] + 1 : Math.max(lengths[i + 1][j], lengths[i][j + 1]);
        }
    }
    const out: DiffLine[] = [];
    let i = 0;
    let j = 0;
    while (i < a.length && j < b.length) {
        if (a[i] === b[j]) {
            out.push({ kind: ' ', text: a[i] });
            i++;
            j++;
        } else if (lengths[i + 1][j] >= lengths[i][j + 1]) {
            out.push({ kind: '-', text: a[i++] });
        } else {
            out.push({ kind: '+', text: b[j++] });
        }
    }
    while (i < a.length) {
        out.push({ kind: '-', text: a[i++] });
    }
    while (j < b.length) {
        out.push({ kind: '+', text: b[j++] });
    }

    return out;
}

/** What an approval card shows for a change: its diff lines (a new file is all added). */
export function changeLines(input: ApprovalInput): DiffLine[] {
    if (input.edits) {
        return input.edits.flatMap((edit, i) => [
            ...(i > 0 ? [{ kind: ' ' as const, text: '…' }] : []),
            ...lineDiff(edit.old, edit.new),
        ]);
    }
    if (typeof input.content === 'string') {
        return lines(input.content).map((text) => ({ kind: '+' as const, text }));
    }

    return [];
}

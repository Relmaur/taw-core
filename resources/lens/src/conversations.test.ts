import { describe, expect, it } from 'vitest';
import { emptyChat, type Chat } from './chatModel';
import { changesLabel, conversationTitle, detailOf, resumedAt, switchBlocked, titleOf, when } from './conversations';

const NOW = new Date(2026, 10, 8, 18, 0);
const local = (d: number, h: number, m: number, y = 2026, mo = 10) => new Date(y, mo, d, h, m).toISOString();

describe('the conversation menu’s words', () => {
    it('titles a conversation by its first line, like the bridge', () => {
        expect(titleOf('\n  Make it smaller \nmore')).toBe('Make it smaller');
        expect(titleOf('x'.repeat(100))).toHaveLength(80);
        expect(conversationTitle(emptyChat)).toBe('New chat');
        expect(
            conversationTitle({ ...emptyChat, turns: [{ turn: 1, text: 'Hero\nsmaller', chips: [], items: [] }] }),
        ).toBe('Hero');
        expect(
            conversationTitle({
                ...emptyChat,
                conversations: [{ id: 'a', title: 'From the bridge', at: null, changes: 0, current: true }],
            }),
        ).toBe('From the bridge');
    });

    it('says when: today, yesterday, or the date (with the year only when it isn’t this one)', () => {
        expect(when(local(8, 10, 12), NOW, 'en-GB')).toBe('Today, 10:12');
        expect(when(local(7, 16, 31), NOW, 'en-GB')).toBe('Yesterday, 16:31');
        expect(when(local(5, 11, 40), NOW, 'en-GB')).toBe('5 Nov, 11:40');
        expect(when(local(5, 11, 40, 2025), NOW, 'en-GB')).toBe('5 Nov 2025, 11:40');
        expect(when(null, NOW)).toBe('');
        expect(when('nonsense', NOW)).toBe('');
        expect(resumedAt(local(8, 10, 12), NOW, 'en-GB')).toBe('Resumed today, 10:12');
        expect(resumedAt(local(7, 9, 5), NOW, 'en-GB')).toBe('Resumed yesterday, 9:05');
        expect(resumedAt(local(1, 9, 5), NOW, 'en-GB')).toBe('Resumed 1 Nov, 9:05');
    });

    it('counts changes, and says “working…” for the current one while Claude works', () => {
        expect([0, 1, 3].map(changesLabel)).toEqual(['no changes', '1 change', '3 changes']);
        const row = { id: 'a', title: 'A', at: local(8, 10, 12), changes: 2, current: true };
        expect(detailOf(row, false, NOW)).toMatch(/^Today, .+ · 2 changes$/);
        expect(detailOf(row, true, NOW)).toMatch(/· working…$/);
        expect(detailOf({ ...row, current: false }, true, NOW)).toMatch(/· 2 changes$/);
        expect(detailOf({ ...row, at: null }, false, NOW)).toBe('2 changes');
    });

    it('blocks switching while Claude works or an approval waits', () => {
        expect(switchBlocked(emptyChat)).toBeNull();
        expect(switchBlocked({ ...emptyChat, working: true })).toBe('Stop Claude first, or wait for it to finish.');
        const waiting: Chat = {
            ...emptyChat,
            turns: [
                {
                    turn: 1,
                    text: 'x',
                    chips: [],
                    items: [
                        {
                            type: 'approval',
                            id: 'a',
                            tool: 'Edit',
                            kind: 'edit',
                            target: 'f',
                            input: {},
                            state: 'pending',
                        },
                    ],
                },
            ],
        };
        expect(switchBlocked(waiting)).not.toBeNull();
    });
});

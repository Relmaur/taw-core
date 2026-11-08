/**
 * The conversation menu's words (component 06, taw-core ADR-0021): the title, when a conversation was last
 * active, how many changes it made, and whether the developer can switch right now. Pure, so it's tested
 * without a page.
 */
import { pendingApproval, type Chat, type ConversationInfo } from './chatModel';
import { __ } from './i18n';

/** The first non-empty line of a message, as the bridge titles conversations (transcript.mjs titleOf). */
export function titleOf(text: string, max = 80): string {
    const line =
        text
            .split('\n')
            .map((l) => l.trim())
            .find((l) => l !== '') ?? '';

    return line.length > max ? `${line.slice(0, max - 1)}…` : line;
}

/** The header's title: the conversation's first message, or "New chat" before there is one. */
export function conversationTitle(chat: Chat): string {
    const current = chat.conversations.find((c) => c.current);
    const title = current?.title || titleOf(chat.turns[0]?.text ?? '');

    return title || __('New chat', 'taw-core');
}

const sameDay = (a: Date, b: Date) =>
    a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();

/** "Today, 10:12", "Yesterday, 16:31", "Nov 5, 11:40" (in the browser's locale and time zone). */
export function when(at: string | null | undefined, now: Date = new Date(), locale?: string): string {
    const date = at ? new Date(at) : null;
    if (!date || Number.isNaN(date.getTime())) {
        return '';
    }
    const time = new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' }).format(date);
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);
    if (sameDay(date, now)) {
        return __('Today, %s', 'taw-core').replace('%s', time);
    }
    if (sameDay(date, yesterday)) {
        return __('Yesterday, %s', 'taw-core').replace('%s', time);
    }
    const day = new Intl.DateTimeFormat(locale, {
        month: 'short',
        day: 'numeric',
        ...(date.getFullYear() !== now.getFullYear() ? { year: 'numeric' as const } : {}),
    }).format(date);

    return `${day}, ${time}`;
}

/** The divider that closes a reopened conversation's history: "Resumed today, 10:12". */
export function resumedAt(at: string, now: Date = new Date(), locale?: string): string {
    const date = new Date(at);
    if (Number.isNaN(date.getTime())) {
        return __('Resumed', 'taw-core');
    }
    const time = new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' }).format(date);
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);
    if (sameDay(date, now)) {
        return __('Resumed today, %s', 'taw-core').replace('%s', time);
    }
    if (sameDay(date, yesterday)) {
        return __('Resumed yesterday, %s', 'taw-core').replace('%s', time);
    }

    return __('Resumed %s', 'taw-core').replace('%s', when(at, now, locale));
}

export function changesLabel(n: number): string {
    if (n <= 0) {
        return __('no changes', 'taw-core');
    }

    return n === 1 ? __('1 change', 'taw-core') : __('%d changes', 'taw-core').replace('%d', String(n));
}

/** A menu row's second line: "Today, 10:12 · 2 changes" ("working…" for the current one while Claude works). */
export function detailOf(conversation: ConversationInfo, working: boolean, now?: Date): string {
    const time = when(conversation.at, now);
    const tail = conversation.current && working ? __('working…', 'taw-core') : changesLabel(conversation.changes);

    return time ? `${time} · ${tail}` : tail;
}

/** Why New chat and the other conversations are off right now, or null when they're on. */
export function switchBlocked(chat: Chat): string | null {
    return chat.working || pendingApproval(chat)
        ? __('Stop Claude first, or wait for it to finish.', 'taw-core')
        : null;
}

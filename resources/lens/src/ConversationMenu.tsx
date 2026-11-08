/**
 * The conversation menu (component 06, screen 15; taw-core ADR-0021): the header's title button, and its menu
 * with New chat and the recent conversations for this theme. The menu is a popover inside the panel, under the
 * header, never on the page.
 */
import { useEffect, useRef } from 'preact/hooks';
import type { Chat } from './chatModel';
import { conversationTitle, detailOf, switchBlocked } from './conversations';
import { __ } from './i18n';
import { CheckIcon, ChevronDownIcon, NewChatIcon } from './icons';

export function TitleButton({
    chat,
    open,
    onToggle,
    buttonRef,
}: {
    chat: Chat;
    open: boolean;
    onToggle(): void;
    buttonRef: { current: HTMLButtonElement | null };
}) {
    const title = conversationTitle(chat);

    return (
        <button
            ref={buttonRef}
            type="button"
            class="conv-title"
            aria-haspopup="menu"
            aria-expanded={open}
            aria-label={__('Conversation: %s. New chat and recent conversations', 'taw-core').replace('%s', title)}
            title={title}
            onClick={onToggle}
            onKeyDown={(event) => {
                if (event.key === 'ArrowDown' && !open) {
                    event.preventDefault();
                    onToggle();
                }
            }}
        >
            <span class="conv-title-text">{title}</span>
            <ChevronDownIcon />
        </button>
    );
}

export function ConversationMenu({
    chat,
    host,
    onNew,
    onOpen,
    onClose,
}: {
    chat: Chat;
    host: string;
    onNew(): void;
    onOpen(id: string): void;
    /** `focusTitle`: give focus back to the title button (Esc), not when Tab or a click moves it on. */
    onClose(focusTitle: boolean): void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const blocked = switchBlocked(chat);
    const items = () => [...(ref.current?.querySelectorAll<HTMLButtonElement>('[role^="menuitem"]') ?? [])];
    const enabled = () => items().filter((item) => item.getAttribute('aria-disabled') !== 'true');

    useEffect(() => {
        (enabled()[0] ?? items()[0])?.focus();
    }, []);

    const onKeyDown = (event: KeyboardEvent) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            onClose(true);
            return;
        }
        if (event.key === 'Tab') {
            onClose(false);
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            const list = enabled();
            if (list.length === 0) {
                return;
            }
            const at = list.indexOf(event.target as HTMLButtonElement);
            const next =
                event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                      ? list.length - 1
                      : (at + (event.key === 'ArrowDown' ? 1 : -1) + list.length) % list.length;
            list[next]?.focus();
        }
    };

    return (
        <div class="conv-menu" role="menu" aria-label={__('Conversations', 'taw-core')} ref={ref} onKeyDown={onKeyDown}>
            <button
                type="button"
                role="menuitem"
                class="conv-new"
                aria-disabled={blocked ? 'true' : undefined}
                onClick={() => !blocked && onNew()}
            >
                <NewChatIcon />
                {__('New chat', 'taw-core')}
            </button>
            {blocked && (
                <div class="conv-reason" role="note">
                    {blocked}
                </div>
            )}
            <div class="conv-rule" role="separator" />
            <div class="conv-label" aria-hidden="true">
                {__('In this theme', 'taw-core')}
            </div>
            {chat.conversations.length === 0 ? (
                <div class="conv-empty">
                    {__(
                        'No earlier conversations in this theme yet. They appear here once you send a message.',
                        'taw-core',
                    )}
                </div>
            ) : (
                chat.conversations.map((conversation) => {
                    const off = !conversation.current && blocked !== null;
                    return (
                        <button
                            key={conversation.id || 'current'}
                            type="button"
                            role="menuitemradio"
                            class="conv-item"
                            aria-checked={conversation.current}
                            aria-disabled={off ? 'true' : undefined}
                            onClick={() => {
                                if (conversation.current) {
                                    onClose(true);
                                } else if (!off) {
                                    onOpen(conversation.id);
                                }
                            }}
                        >
                            <span class="conv-tick" aria-hidden="true">
                                {conversation.current && <CheckIcon />}
                            </span>
                            <span class="conv-text">
                                <span class="conv-name">{conversation.title}</span>
                                <span class="conv-detail">{detailOf(conversation, chat.working)}</span>
                            </span>
                        </button>
                    );
                })
            )}
            {host && <div class="conv-host">{host}</div>}
        </div>
    );
}

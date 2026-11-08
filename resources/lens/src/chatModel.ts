/**
 * The conversation as the panel shows it, built from the bridge's events (resources/lens-bridge, page protocol
 * 1). Pure: the same events always make the same conversation, so a page that reloads and replays the
 * session's history ends up exactly where a page that watched it live did.
 */

export interface Busy {
    pids: number[];
    /** When the oldest other session started (ISO), if known. */
    since: string | null;
}

export interface ApprovalInput {
    file?: string;
    edits?: { old: string; new: string }[];
    content?: string;
    command?: string;
    description?: string;
    json?: string;
}

/** One conversation in the menu (taw-core ADR-0021). `id` is '' for a new one Claude hasn't named yet. */
export interface ConversationInfo {
    id: string;
    title: string;
    /** When it was last active (ISO), if known. */
    at: string | null;
    /** Files Claude changed and fields saved from the page, each counted once. */
    changes: number;
    current: boolean;
}

/** A field saved from the page, as the conversation recorded it (no Undo: that's the page's own note). */
export interface Save {
    id: string;
    name: string;
    undone: boolean;
    /** Shown after this many turns. */
    after: number;
}

export type BridgeEvent =
    | {
          type: 'hello';
          protocol: number;
          session: string | null;
          working: boolean;
          busy: Busy | null;
          takenOver: boolean;
          history: BridgeEvent[];
          conversations?: ConversationInfo[];
      }
    | { type: 'turn'; turn: number; text: string; chips: string[]; from?: 'edit'; at?: string; page?: string }
    | { type: 'text'; turn: number; delta: string }
    | { type: 'tool'; turn: number; id: string; name: string; kind: StepKind; target: string }
    | { type: 'tool-done'; turn: number; id: string; ok: boolean }
    | { type: 'approval'; turn: number; id: string; tool: string; kind: StepKind; target: string; input: ApprovalInput }
    | { type: 'approval-done'; id: string; allow: boolean }
    | {
          type: 'done';
          turn: number;
          outcome: 'ok' | 'stopped' | 'error';
          message: string;
          detail?: string;
          changed: string[];
          at?: string;
      }
    | { type: 'busy'; busy: Busy | null; takenOver: boolean }
    | { type: 'saved'; id: string; name: string; undone?: boolean; at?: string }
    /** The conversation was reopened here: what came before is its replayed history. */
    | { type: 'resumed'; at: string }
    | { type: 'conversations'; conversations: ConversationInfo[] };

export type StepKind = 'read' | 'search' | 'edit' | 'write' | 'run' | 'skill' | 'plan' | 'other';

export interface Step {
    id: string;
    name: string;
    kind: StepKind;
    target: string;
    state: 'running' | 'done' | 'failed';
}

export type Item =
    | { type: 'text'; text: string }
    | { type: 'steps'; steps: Step[] }
    | {
          type: 'approval';
          id: string;
          tool: string;
          kind: StepKind;
          target: string;
          input: ApprovalInput;
          state: 'pending' | 'sending' | 'applied' | 'skipped';
      };

export interface Turn {
    turn: number;
    text: string;
    chips: string[];
    /** The page wrote this message for an edit made on the page (template text). */
    from?: 'edit';
    /** When it was sent (ISO), and the path of the page it was sent from. */
    at?: string;
    page?: string;
    items: Item[];
    outcome?: 'ok' | 'stopped' | 'error';
    message?: string;
    detail?: string;
    changed?: string[];
}

export interface Chat {
    turns: Turn[];
    working: boolean;
    busy: Busy | null;
    takenOver: boolean;
    session: string | null;
    conversations: ConversationInfo[];
    saves: Save[];
    /**
     * Where a reopened conversation's replayed history ends: after this many turns and recorded saves, and
     * when it was reopened.
     */
    resumed: { after: number; saves: number; at: string } | null;
}

export const emptyChat: Chat = {
    turns: [],
    working: false,
    busy: null,
    takenOver: false,
    session: null,
    conversations: [],
    saves: [],
    resumed: null,
};

/** A turn the connection dropped in the middle of: ended as an error, with what it had changed so far. */
export const DROPPED = 'connection';

const withTurn = (chat: Chat, turn: number, change: (t: Turn) => Turn): Chat => {
    const index = chat.turns.findIndex((t) => t.turn === turn);
    if (index < 0) {
        return chat;
    }
    const turns = chat.turns.slice();
    turns[index] = change(turns[index]);

    return { ...chat, turns };
};

const lastItem = (turn: Turn): Item | undefined => turn.items[turn.items.length - 1];

const updateApproval = (chat: Chat, id: string, state: 'sending' | 'applied' | 'skipped' | 'pending'): Chat => {
    for (const turn of chat.turns) {
        if (turn.items.some((item) => item.type === 'approval' && item.id === id)) {
            return withTurn(chat, turn.turn, (t) => ({
                ...t,
                items: t.items.map((item) => (item.type === 'approval' && item.id === id ? { ...item, state } : item)),
            }));
        }
    }

    return chat;
};

export function reduce(chat: Chat, event: BridgeEvent): Chat {
    switch (event.type) {
        case 'hello':
            return {
                ...event.history.reduce(reduce, {
                    ...emptyChat,
                    session: event.session,
                    conversations: event.conversations ?? [],
                }),
                working: event.working,
                busy: event.busy,
                takenOver: event.takenOver,
                session: event.session,
            };
        case 'turn':
            return {
                ...chat,
                working: true,
                turns: [
                    ...chat.turns,
                    {
                        turn: event.turn,
                        text: event.text,
                        chips: event.chips ?? [],
                        items: [],
                        ...(event.from === 'edit' ? { from: 'edit' as const } : {}),
                        ...(event.at ? { at: event.at } : {}),
                        ...(event.page ? { page: event.page } : {}),
                    },
                ],
            };
        case 'text':
            return withTurn(chat, event.turn, (t) => {
                const last = lastItem(t);
                const items =
                    last?.type === 'text'
                        ? [...t.items.slice(0, -1), { type: 'text' as const, text: last.text + event.delta }]
                        : [...t.items, { type: 'text' as const, text: event.delta }];
                return { ...t, items };
            });
        case 'tool':
            return withTurn(chat, event.turn, (t) => {
                const step: Step = {
                    id: event.id,
                    name: event.name,
                    kind: event.kind,
                    target: event.target,
                    state: 'running',
                };
                const last = lastItem(t);
                const items =
                    last?.type === 'steps'
                        ? [...t.items.slice(0, -1), { type: 'steps' as const, steps: [...last.steps, step] }]
                        : [...t.items, { type: 'steps' as const, steps: [step] }];
                return { ...t, items };
            });
        case 'tool-done':
            return withTurn(chat, event.turn, (t) => ({
                ...t,
                items: t.items.map((item) =>
                    item.type === 'steps'
                        ? {
                              ...item,
                              steps: item.steps.map((s) =>
                                  s.id === event.id
                                      ? { ...s, state: event.ok ? ('done' as const) : ('failed' as const) }
                                      : s,
                              ),
                          }
                        : item,
                ),
            }));
        case 'approval':
            return withTurn(chat, event.turn, (t) => ({
                ...t,
                items: [
                    ...t.items,
                    {
                        type: 'approval',
                        id: event.id,
                        tool: event.tool,
                        kind: event.kind,
                        target: event.target,
                        input: event.input ?? {},
                        state: 'pending',
                    },
                ],
            }));
        case 'approval-done':
            return updateApproval(chat, event.id, event.allow ? 'applied' : 'skipped');
        case 'done':
            return withTurn({ ...chat, working: false }, event.turn, (t) => ({
                ...t,
                // A step still running when the turn ended never finished.
                items: t.items.map((item) =>
                    item.type === 'steps'
                        ? {
                              ...item,
                              steps: item.steps.map((s) =>
                                  s.state === 'running' ? { ...s, state: 'failed' as const } : s,
                              ),
                          }
                        : item.type === 'approval' && item.state !== 'applied'
                          ? {
                                ...item,
                                state:
                                    item.state === 'pending' || item.state === 'sending'
                                        ? ('skipped' as const)
                                        : item.state,
                            }
                          : item,
                ),
                outcome: event.outcome,
                message: event.message,
                detail: event.detail,
                changed: event.changed ?? [],
            }));
        case 'busy':
            return { ...chat, busy: event.busy, takenOver: event.takenOver };
        case 'saved':
            return chat.saves.some((s) => s.id === event.id)
                ? {
                      ...chat,
                      saves: chat.saves.map((s) => (s.id === event.id ? { ...s, undone: event.undone === true } : s)),
                  }
                : {
                      ...chat,
                      saves: [
                          ...chat.saves,
                          { id: event.id, name: event.name, undone: event.undone === true, after: chat.turns.length },
                      ],
                  };
        case 'resumed':
            return { ...chat, resumed: { after: chat.turns.length, saves: chat.saves.length, at: event.at } };
        case 'conversations':
            return { ...chat, conversations: event.conversations };
        default:
            return chat;
    }
}

/** The page lost the bridge: a turn in progress ends as dropped (the bridge's history has the truth on reconnect). */
export function dropped(chat: Chat): Chat {
    const open = chat.turns.find((t) => t.outcome === undefined);
    if (!chat.working || !open) {
        return { ...chat, working: false };
    }

    return reduce(chat, { type: 'done', turn: open.turn, outcome: 'error', message: DROPPED, changed: [] });
}

/** Marks an approval as being answered before the bridge confirms (`sending`), or back to `pending` if that failed. */
export const markApproval = (chat: Chat, id: string, state: 'sending' | 'pending'): Chat =>
    updateApproval(chat, id, state);

/** The approval the developer still has to answer, if any. */
export function pendingApproval(chat: Chat): Extract<Item, { type: 'approval' }> | null {
    for (const turn of chat.turns) {
        for (const item of turn.items) {
            if (item.type === 'approval' && (item.state === 'pending' || item.state === 'sending')) {
                return item;
            }
        }
    }

    return null;
}

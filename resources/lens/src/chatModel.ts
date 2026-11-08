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

export type BridgeEvent =
    | {
          type: 'hello';
          protocol: number;
          session: string | null;
          working: boolean;
          busy: Busy | null;
          takenOver: boolean;
          history: BridgeEvent[];
      }
    | { type: 'turn'; turn: number; text: string; chips: string[] }
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
      }
    | { type: 'busy'; busy: Busy | null; takenOver: boolean };

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
}

export const emptyChat: Chat = { turns: [], working: false, busy: null, takenOver: false, session: null };

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
                ...event.history.reduce(reduce, { ...emptyChat, session: event.session }),
                working: event.working,
                busy: event.busy,
                takenOver: event.takenOver,
                session: event.session,
            };
        case 'turn':
            return {
                ...chat,
                working: true,
                turns: [...chat.turns, { turn: event.turn, text: event.text, chips: event.chips ?? [], items: [] }],
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

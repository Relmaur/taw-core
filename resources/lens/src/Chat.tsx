/**
 * The conversation (component 01; states from component 04, screen 07): your messages with their selection
 * chips, Claude's streamed replies, the quiet activity line, approval cards (amber: needs your OK), and the
 * error, theme-busy, not-connected and connecting states. Errors are ink, never red.
 */
import { Fragment } from 'preact';
import { useState } from 'preact/hooks';
import { activity, stepDetail } from './activity';
import type { Connection } from './bridge';
import { DROPPED, type Busy, type Chat, type Item, type Step, type Turn } from './chatModel';
import { changeLines, SHOWN_MAX } from './diff';
import { __ } from './i18n';
import { AlertIcon, CheckIcon, FailedIcon, SpinnerIcon, UnlinkIcon } from './icons';
import { Markdown } from './markdown';

export const START_COMMAND = 'taw lens';

/** The part of a line before and after its `%s`, with the code between. */
function Line({ text, code }: { text: string; code: string }) {
    const [before, after = ''] = text.split('%s');
    return (
        <span class="step-text">
            {before}
            {code && <code>{code}</code>}
            {after}
        </span>
    );
}

const StepState = ({ state }: { state: Step['state'] }) =>
    state === 'running' ? (
        <span class="step-icon">
            <SpinnerIcon size={13} />
            <span class="sr-only">{__('In progress', 'taw-core')}</span>
        </span>
    ) : state === 'failed' ? (
        <span class="step-icon">
            <FailedIcon size={13} />
            <span class="sr-only">{__('Didn’t finish', 'taw-core')}</span>
        </span>
    ) : (
        <span class="step-icon">
            <CheckIcon size={13} />
            <span class="sr-only">{__('Done', 'taw-core')}</span>
        </span>
    );

export function Activity({ steps }: { steps: Step[] }) {
    const [open, setOpen] = useState(false);
    const lines = activity(steps);
    return (
        <div class="activity">
            {lines.map((line, i) => (
                <div class="step" key={i}>
                    <StepState state={line.state} />
                    <Line text={line.text} code={line.code} />
                    {i === 0 && (
                        <button
                            type="button"
                            class="text-button step-details"
                            aria-expanded={open}
                            onClick={() => setOpen(!open)}
                        >
                            {open ? __('Hide details', 'taw-core') : __('Details', 'taw-core')}
                        </button>
                    )}
                </div>
            ))}
            {open && (
                <ul class="step-list">
                    {steps.map((step) => (
                        <li key={step.id}>
                            <StepState state={step.state} />
                            <code>{stepDetail(step)}</code>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function approvalTitle(item: Extract<Item, { type: 'approval' }>): string {
    switch (item.kind) {
        case 'edit':
            return __('Edit 1 file', 'taw-core');
        case 'write':
            return __('Write 1 file', 'taw-core');
        case 'run':
            return __('Run a command', 'taw-core');
        default:
            return __('Use %s', 'taw-core').replace('%s', item.tool);
    }
}

export function ApprovalCard({
    item,
    onAnswer,
}: {
    item: Extract<Item, { type: 'approval' }>;
    onAnswer(id: string, allow: boolean): void;
}) {
    const title = approvalTitle(item);
    const titleId = `approval-${item.id}`;
    const file = item.input.file ?? '';
    if (item.state === 'applied' || item.state === 'skipped') {
        return (
            <div class="approval-done">
                <span class="step-icon">
                    {item.state === 'applied' ? <CheckIcon size={13} /> : <FailedIcon size={13} />}
                </span>
                <Line
                    text={item.state === 'applied' ? __('Applied: %s', 'taw-core') : __('Skipped: %s', 'taw-core')}
                    code={file || item.target || item.tool}
                />
            </div>
        );
    }
    const lines = changeLines(item.input);
    const shown = lines.slice(0, SHOWN_MAX);
    const fileChange = item.kind === 'edit' || item.kind === 'write';

    return (
        <div class="approval" role="group" aria-labelledby={titleId}>
            <div class="approval-head">
                <span class="approval-dot" aria-hidden="true" />
                <span class="approval-title" id={titleId}>
                    {title}
                </span>
                {file && <span class="approval-file">{file}</span>}
            </div>
            {lines.length > 0 ? (
                <pre class="diff" aria-label={__('Proposed change', 'taw-core')}>
                    {shown.map((line, i) => (
                        <div
                            class="diff-line"
                            data-kind={line.kind === '-' ? 'removed' : line.kind === '+' ? 'added' : undefined}
                            key={i}
                        >
                            <span class="diff-sign" aria-hidden="true">
                                {line.kind === ' ' ? ' ' : line.kind}
                            </span>
                            <span class="sr-only">
                                {line.kind === '-'
                                    ? __('Removed:', 'taw-core')
                                    : line.kind === '+'
                                      ? __('Added:', 'taw-core')
                                      : ''}
                            </span>
                            {line.text}
                        </div>
                    ))}
                    {lines.length > shown.length && (
                        <div class="diff-line diff-more">
                            {__('… %d more lines', 'taw-core').replace('%d', String(lines.length - shown.length))}
                        </div>
                    )}
                </pre>
            ) : item.input.command !== undefined ? (
                <div class="approval-body">
                    {item.input.description && <div class="approval-note">{item.input.description}</div>}
                    <pre class="approval-code">{item.input.command}</pre>
                </div>
            ) : item.input.json !== undefined ? (
                <div class="approval-body">
                    <pre class="approval-code">{item.input.json}</pre>
                </div>
            ) : null}
            <div class="approval-actions">
                <button
                    type="button"
                    class="card-button primary"
                    disabled={item.state === 'sending'}
                    onClick={() => onAnswer(item.id, true)}
                >
                    {fileChange ? __('Apply', 'taw-core') : __('Allow', 'taw-core')}
                </button>
                <button
                    type="button"
                    class="card-button"
                    disabled={item.state === 'sending'}
                    onClick={() => onAnswer(item.id, false)}
                >
                    {__('Skip', 'taw-core')}
                </button>
            </div>
        </div>
    );
}

export function ErrorCard({ turn, onRetry, retry }: { turn: Turn; onRetry(turn: Turn): void; retry: boolean }) {
    const [open, setOpen] = useState(false);
    const changed = turn.changed ?? [];
    const why =
        turn.message === DROPPED || turn.message === 'agent-exit'
            ? __('The connection to Claude dropped.', 'taw-core')
            : __('Claude reported an error.', 'taw-core');
    const detail = [turn.message !== DROPPED && turn.message !== 'agent-exit' ? turn.message : '', turn.detail ?? '']
        .filter(Boolean)
        .join('\n\n');

    return (
        <div class="notice" role="alert">
            <div class="notice-head">
                <AlertIcon size={15} />
                <span>{__('Claude stopped before finishing', 'taw-core')}</span>
            </div>
            <p class="notice-text">
                {why}{' '}
                {changed.length
                    ? __('It had changed %s by then.', 'taw-core').replace('%s', changed.join(', '))
                    : __('Nothing was changed after the last step you saw.', 'taw-core')}
            </p>
            <div class="notice-actions">
                <button type="button" class="card-button primary" disabled={!retry} onClick={() => onRetry(turn)}>
                    {__('Try again', 'taw-core')}
                </button>
                {detail && (
                    <button type="button" class="card-button" aria-expanded={open} onClick={() => setOpen(!open)}>
                        {__('Details', 'taw-core')}
                    </button>
                )}
            </div>
            {open && detail && <pre class="notice-detail">{detail}</pre>}
        </div>
    );
}

const timeOf = (iso: string | null): string => {
    if (!iso) {
        return '';
    }
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

export function BusyCard({ busy, takenOver, onTakeOver }: { busy: Busy; takenOver: boolean; onTakeOver(): void }) {
    const [state, setState] = useState<'open' | 'waiting' | 'confirm'>('open');
    if (takenOver) {
        return (
            <div class="busy-line">
                {__(
                    'You took over this theme. The other Claude session is still running: you may overwrite each other’s changes.',
                    'taw-core',
                )}
            </div>
        );
    }
    const at = timeOf(busy.since);
    if (state === 'waiting') {
        return (
            <div class="busy busy-compact" role="status">
                <span class="approval-dot" aria-hidden="true" />
                <span>
                    {__('Waiting for the other Claude session. Lens writes no files until it’s free.', 'taw-core')}
                </span>
                <button type="button" class="text-button" onClick={() => setState('confirm')}>
                    {__('Take over', 'taw-core')}
                </button>
            </div>
        );
    }

    return (
        <div class="busy" role="status">
            <div class="busy-head">
                <span class="approval-dot" aria-hidden="true" />
                <span>
                    {state === 'confirm'
                        ? __('Take over the theme?', 'taw-core')
                        : __('Another Claude session is editing this theme', 'taw-core')}
                </span>
            </div>
            <p class="busy-text">
                {state === 'confirm'
                    ? __(
                          'Lens will write files again while the other session keeps running. If you both change the same file, one of you overwrites the other.',
                          'taw-core',
                      )
                    : `${at ? __('A Claude session started at %s holds the theme.', 'taw-core').replace('%s', at) : __('A Claude session holds the theme.', 'taw-core')} ${__('You can keep picking and asking; Lens won’t write files until it’s free.', 'taw-core')}`}
            </p>
            <div class="busy-actions">
                {state === 'confirm' ? (
                    <>
                        <button type="button" class="card-button primary" onClick={onTakeOver}>
                            {__('Take over', 'taw-core')}
                        </button>
                        <button type="button" class="card-button" onClick={() => setState('open')}>
                            {__('Cancel', 'taw-core')}
                        </button>
                    </>
                ) : (
                    <>
                        <button type="button" class="card-button primary" onClick={() => setState('waiting')}>
                            {__('Wait for it', 'taw-core')}
                        </button>
                        <button type="button" class="card-button" onClick={() => setState('confirm')}>
                            {__('Take over', 'taw-core')}
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}

export function NotConnected({ outdated }: { outdated: boolean }) {
    const [copied, setCopied] = useState(false);
    const copy = () => {
        void navigator.clipboard?.writeText(START_COMMAND).then(
            () => {
                setCopied(true);
                setTimeout(() => setCopied(false), 1500);
            },
            () => {},
        );
    };

    return (
        <div class="empty">
            <UnlinkIcon />
            <div class="empty-title">
                {outdated ? __('Lens needs a restart', 'taw-core') : __('Lens isn’t connected', 'taw-core')}
            </div>
            <div class="empty-text">
                {outdated
                    ? __(
                          'The running taw lens is from another taw/core version. Stop it (Ctrl-C) and start it again in this site’s theme folder:',
                          'taw-core',
                      )
                    : __('Start it in this site’s theme folder:', 'taw-core')}
            </div>
            <div class="command">
                <code>{START_COMMAND}</code>
                <button type="button" class="command-copy" onClick={copy}>
                    {copied ? __('Copied', 'taw-core') : __('Copy', 'taw-core')}
                </button>
            </div>
            <div class="empty-note">{__('Lens reconnects on its own once it’s running.', 'taw-core')}</div>
            <div class="empty-note">{__('Picking and the inspect card still work.', 'taw-core')}</div>
        </div>
    );
}

export const Connecting = () => (
    <div class="skeleton" aria-hidden="true">
        <span style={{ width: '58%' }} />
        <span style={{ width: '82%' }} />
        <span style={{ width: '38%' }} />
    </div>
);

function TurnView({
    turn,
    last,
    working,
    onAnswer,
    onRetry,
    retry,
}: {
    turn: Turn;
    last: boolean;
    working: boolean;
    onAnswer(id: string, allow: boolean): void;
    onRetry(turn: Turn): void;
    retry: boolean;
}) {
    const tail = turn.items[turn.items.length - 1];
    // Working with nothing moving on screen yet (before the first word, or between steps): say so.
    const thinking =
        last &&
        working &&
        turn.outcome === undefined &&
        (!tail || (tail.type === 'steps' && tail.steps.every((s) => s.state !== 'running')));
    return (
        <div class="turn">
            <div class="message">
                {turn.chips.length > 0 && (
                    <div class="message-chips">
                        {turn.chips.map((chip, i) => (
                            <span class="chip" key={i}>
                                {chip}
                            </span>
                        ))}
                    </div>
                )}
                <div class="bubble">{turn.text}</div>
                {turn.from === 'edit' && (
                    <span class="message-from">{__('Sent from an edit on the page', 'taw-core')}</span>
                )}
            </div>
            {turn.items.map((item, i) =>
                item.type === 'text' ? (
                    <div class="reply" key={i}>
                        <Markdown source={item.text} />
                    </div>
                ) : item.type === 'steps' ? (
                    <Activity steps={item.steps} key={i} />
                ) : (
                    <ApprovalCard item={item} onAnswer={onAnswer} key={item.id} />
                ),
            )}
            {thinking && (
                <div class="activity">
                    <div class="step">
                        <StepState state="running" />
                        <span class="step-text">{__('Thinking…', 'taw-core')}</span>
                    </div>
                </div>
            )}
            {turn.outcome === 'stopped' && <div class="turn-note">{__('Stopped.', 'taw-core')}</div>}
            {turn.outcome === 'error' && <ErrorCard turn={turn} onRetry={onRetry} retry={retry} />}
        </div>
    );
}

/** A field saved from an edit on the page (component 05, screen 09), in the conversation where it happened. */
export interface EditNote {
    id: number;
    /** Shown after this many turns. */
    after: number;
    /** The field's name. */
    name: string;
    state: 'saved' | 'undoing' | 'undone';
    error?: string;
}

export function SavedNote({ note, onUndo }: { note: EditNote; onUndo(id: number): void }) {
    return (
        <div class="saved">
            <div class="saved-line">
                <CheckIcon />
                <span class="saved-text">
                    {note.state === 'undone' ? __('Undid the change to', 'taw-core') : __('Saved to', 'taw-core')}{' '}
                    <code class="mono">{note.name}</code>
                </span>
                {note.state === 'saved' && (
                    <button type="button" class="saved-undo" onClick={() => onUndo(note.id)}>
                        {__('Undo', 'taw-core')}
                    </button>
                )}
                {note.state === 'undoing' && (
                    <span class="saved-undo" aria-label={__('Undoing…', 'taw-core')}>
                        <SpinnerIcon size={12} />
                    </span>
                )}
            </div>
            {note.error && (
                <div class="saved-error" role="alert">
                    {note.error}
                </div>
            )}
        </div>
    );
}

export function Conversation({
    chat,
    connection,
    notes = [],
    onAnswer,
    onRetry,
    onTakeOver,
    onUndo = () => undefined,
}: {
    chat: Chat;
    connection: Connection;
    notes?: EditNote[];
    onAnswer(id: string, allow: boolean): void;
    onRetry(turn: Turn): void;
    onTakeOver(): void;
    onUndo?(id: number): void;
}) {
    const live = connection === 'connected';
    const notesAt = (i: number) =>
        notes
            .filter((note) => (i < chat.turns.length ? note.after === i : note.after >= i))
            .map((note) => <SavedNote key={`note-${note.id}`} note={note} onUndo={onUndo} />);
    return (
        <div class="conversation" role="log" aria-label={__('Conversation', 'taw-core')}>
            {chat.busy && live && <BusyCard busy={chat.busy} takenOver={chat.takenOver} onTakeOver={onTakeOver} />}
            {chat.turns.map((turn, i) => (
                <Fragment key={turn.turn}>
                    {notesAt(i)}
                    <TurnView
                        turn={turn}
                        last={i === chat.turns.length - 1}
                        working={chat.working}
                        onAnswer={onAnswer}
                        onRetry={onRetry}
                        retry={live && !chat.working && i === chat.turns.length - 1}
                    />
                </Fragment>
            ))}
            {notesAt(chat.turns.length)}
        </div>
    );
}

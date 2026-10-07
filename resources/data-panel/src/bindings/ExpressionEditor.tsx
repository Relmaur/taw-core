/**
 * The Expression tab's editor (ADR-0012, ADR-0015): a plain textarea with `@`
 * suggestions (values, then functions) and `.` function suggestions, a
 * Functions picker, a live preview from the server, and errors from the
 * TypeScript parser and the server, with "did you mean" for misspelled
 * functions.
 */
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Dashicon } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { completionAt, namesIn, parseExpression } from './expression';
import { didYouMean, functionDocs, groupLabel, type FunctionDoc } from './functionDocs';
import type { ExpressionError } from './expression';
import { previewExpression } from './preview';
import {
    errorMessage,
    functionSuggestions,
    HELPER_FUNCTIONS,
    listNames,
    nameSuggestions,
    type ValueOption,
} from './tags';

interface Problem {
    key: string;
    text: string;
    name: string;
    /** A function the author probably meant. */
    hint?: string;
}

/** The identifier at a character offset (an error's position). */
function wordAt(value: string, at: number): string {
    return /^[A-Za-z_][A-Za-z0-9_]*/.exec(Array.from(value).slice(at).join(''))?.[0] ?? '';
}

/** The Functions picker: every function by group, searchable; picking one types `@name()`. */
function FunctionPicker({ onPick }: { onPick: (doc: FunctionDoc) => void }) {
    const [search, setSearch] = useState('');
    const needle = search.trim().toLowerCase();
    const docs = functionDocs().filter(
        (d) => !needle || `${d.name} ${d.description} ${groupLabel(d.group)}`.toLowerCase().includes(needle),
    );
    const groups = [...new Set(docs.map((d) => d.group))];
    return (
        <div className="taw-function-picker">
            <input
                type="search"
                className="taw-function-picker__search"
                aria-label={__('Search functions', 'taw-core')}
                placeholder={__('Search functions', 'taw-core')}
                value={search}
                onChange={(event) => setSearch(event.target.value)}
            />
            <div className="taw-function-picker__list">
                {groups.map((group) => (
                    <div key={group} role="group" aria-label={groupLabel(group)}>
                        <h4>{groupLabel(group)}</h4>
                        {docs
                            .filter((d) => d.group === group)
                            .map((d) => (
                                <button
                                    key={d.name}
                                    type="button"
                                    onMouseDown={(event) => event.preventDefault()}
                                    onClick={() => onPick(d)}
                                >
                                    <code>{d.usage}</code>
                                    <span>{d.description}</span>
                                    <small>{d.example}</small>
                                </button>
                            ))}
                    </div>
                ))}
                {docs.length === 0 && <p>{__('No functions match your search.', 'taw-core')}</p>}
            </div>
        </div>
    );
}

interface Suggestion {
    key: string;
    label: string;
    detail: string;
    insert: string;
}

/** Names that aren't values the popup knows (after the parser's own errors). */
function unknownNames(names: string[], options: ValueOption[]): string[] {
    const known = new Set([...options.map((o) => o.name), ...listNames(window.tawBindings?.loop)]);
    return [...new Set(names)].filter(
        (name) => !known.has(name) && !(name.startsWith('post.') && known.has(name.slice(5))),
    );
}

/** What a preview reports: the value and the server's errors, plus anything a custom preview adds. */
export interface PreviewResult {
    value: string;
    errors: ExpressionError[];
    [key: string]: unknown;
}

export function ExpressionEditor({
    value,
    onChange,
    options,
    autoFocus = true,
    compact = false,
    placeholder,
    preview: fetchPreview = (expr: string) => previewExpression(expr),
    renderPreview,
    label,
}: {
    value: string;
    onChange: (next: string) => void;
    options: ValueOption[];
    autoFocus?: boolean;
    /** The sidebar's one-setting form: two rows and the main helpers only. */
    compact?: boolean;
    placeholder?: string;
    /** Asks the server for the preview (default: the expression's text). */
    preview?: (expr: string) => Promise<PreviewResult>;
    /** Shows a preview result (default: its text, or "(empty for this post)"). */
    renderPreview?: (result: PreviewResult) => React.ReactNode;
    label?: string;
}) {
    const ref = useRef<HTMLTextAreaElement>(null);
    const [caret, setCaret] = useState(value.length);
    const [active, setActive] = useState(0);
    // Suggestions open once the user types, not for a prefilled expression.
    const [dismissed, setDismissed] = useState(value !== '');
    // The preview and the expression it belongs to (a stale one isn't shown).
    const [preview, setPreview] = useState<{ for: string; result: PreviewResult } | null>(null);
    const [picking, setPicking] = useState(false);
    // The latest preview function, without re-running the preview each render.
    const fetchRef = useRef(fetchPreview);
    useEffect(() => {
        fetchRef.current = fetchPreview;
    });

    const parsed = useMemo(() => parseExpression(value), [value]);
    const unknown = unknownNames(namesIn(parsed), options);

    const suggestions: Suggestion[] = useMemo(() => {
        const completion = completionAt(value.slice(0, caret));
        if (!completion) return [];
        if (completion.kind === 'function') {
            return functionSuggestions(completion.prefix).map((s) => ({
                key: s.fn,
                label: s.insert,
                detail: __('function', 'taw-core'),
                insert: s.insert,
            }));
        }
        const values = nameSuggestions(options, completion.prefix).map((o) => ({
            key: o.key,
            label: `@${o.name}`,
            detail: `${o.group} › ${o.label}`,
            insert: o.name,
        }));
        // Functions too, after the values: `@ro` → `@round(`.
        const prefix = completion.prefix.toLowerCase();
        const functions =
            prefix === '' || prefix.includes('.')
                ? []
                : functionDocs()
                      .filter((d) => d.name.toLowerCase().startsWith(prefix))
                      .slice(0, 5)
                      .map((d) => ({
                          key: `fn:${d.name}`,
                          label: `@${d.name}(…)`,
                          detail: d.description,
                          insert: `${d.name}(`,
                      }));
        return [...values, ...functions];
    }, [value, caret, options]);

    const open = !dismissed && suggestions.length > 0;

    // Debounced server preview.
    useEffect(() => {
        if (value.trim() === '') return undefined;
        let current = true;
        const timer = setTimeout(() => {
            void fetchRef.current(value).then((result) => {
                if (current) setPreview({ for: value, result });
            });
        }, 350);
        return () => {
            current = false;
            clearTimeout(timer);
        };
    }, [value]);

    const accept = (suggestion: Suggestion) => {
        const completion = completionAt(value.slice(0, caret));
        if (!completion) return;
        const before = value.slice(0, caret - completion.prefix.length);
        const next = before + suggestion.insert + value.slice(caret);
        const at = before.length + suggestion.insert.length;
        onChange(next);
        setActive(0);
        requestAnimationFrame(() => {
            ref.current?.focus();
            ref.current?.setSelectionRange(at, at);
            setCaret(at);
        });
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (!open) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            setActive((a) => (a + step + suggestions.length) % suggestions.length);
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            accept(suggestions[Math.min(active, suggestions.length - 1)]);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            setDismissed(true);
        }
    };

    const track = () => setCaret(ref.current?.selectionStart ?? value.length);

    /** Type text at the caret (the helper chips); `back` leaves the caret that many characters before its end. */
    const typeAtCaret = (text: string, back = 0) => {
        const next = value.slice(0, caret) + text + value.slice(caret);
        const at = caret + text.length - back;
        onChange(next);
        setDismissed(false);
        setActive(0);
        requestAnimationFrame(() => {
            ref.current?.focus();
            ref.current?.setSelectionRange(at, at);
            setCaret(at);
        });
    };

    const showPreview = value.trim() !== '' && preview !== null && preview.for === value;
    // The server also reports what only evaluation finds (division by zero, text in arithmetic).
    const serverOnly = showPreview
        ? preview.result.errors.filter((e) => !parsed.errors.some((p) => p.code === e.code && p.at === e.at))
        : [];
    const chars = Array.from(value);
    const problems: Problem[] = [
        ...[...parsed.errors, ...serverOnly].map((error) => {
            const hint = error.code === 'unknown_function' ? didYouMean(wordAt(value, error.at)) : null;
            return {
                key: `${error.code}-${error.at}`,
                text: errorMessage(error.code),
                name: '',
                hint: hint ?? undefined,
            };
        }),
        ...unknown.map((name): Problem => {
            // `@rond(2)` reads as a value named "rond" (then text): it was probably a function.
            const called = !name.includes('.') && chars.join('').includes(`@${name}(`);
            const hint = called ? didYouMean(name) : null;
            return hint
                ? { key: `unknown-${name}`, text: __('Unknown function:', 'taw-core'), name, hint }
                : { key: `unknown-${name}`, text: __('Not a value this post has:', 'taw-core'), name };
        }),
    ];

    return (
        <div className={compact ? 'taw-expression-editor is-compact' : 'taw-expression-editor'}>
            <div className="taw-expression-field">
                <textarea
                    ref={ref}
                    aria-label={label ?? __('Expression', 'taw-core')}
                    rows={compact ? 2 : 3}
                    autoFocus={autoFocus}
                    spellCheck={false}
                    value={value}
                    placeholder={
                        placeholder ?? __("Published on @post.date.format('F j, Y') by @post.author", 'taw-core')
                    }
                    onChange={(event) => {
                        onChange(event.target.value);
                        setCaret(event.target.selectionStart ?? event.target.value.length);
                        setDismissed(false);
                        setActive(0);
                    }}
                    onKeyDown={onKeyDown}
                    onKeyUp={track}
                    onClick={track}
                />
                {open && (
                    <ul
                        role="listbox"
                        aria-label={__('Suggestions', 'taw-core')}
                        className="taw-expression-suggestions"
                    >
                        {suggestions.map((s, index) => (
                            <li key={s.key} role="option" aria-selected={index === active}>
                                <button
                                    type="button"
                                    className={index === active ? 'is-active' : undefined}
                                    onMouseDown={(event) => event.preventDefault()}
                                    onClick={() => accept(s)}
                                >
                                    <code>{s.label}</code>
                                    <span>{s.detail}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <div className="taw-expression-helpers" aria-label={__('Add to the expression', 'taw-core')}>
                <button type="button" onMouseDown={(event) => event.preventDefault()} onClick={() => typeAtCaret('@')}>
                    {__('@ value', 'taw-core')}
                </button>
                {!compact && (
                    <button
                        type="button"
                        title={__('A formula, e.g. @(@price * 1.16)', 'taw-core')}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => typeAtCaret('@()', 1)}
                    >
                        {__('@( formula )', 'taw-core')}
                    </button>
                )}
                <button
                    type="button"
                    title={__("Choose text: @if(@stock > 0, 'In stock', 'Sold out')", 'taw-core')}
                    onMouseDown={(event) => event.preventDefault()}
                    onClick={() => typeAtCaret("@if(, '', '')", 9)}
                >
                    {__('@if( … )', 'taw-core')}
                </button>
                <button
                    type="button"
                    className={picking ? 'is-pressed' : undefined}
                    aria-expanded={picking}
                    onMouseDown={(event) => event.preventDefault()}
                    onClick={() => setPicking(!picking)}
                >
                    {__('ƒ Functions', 'taw-core')}
                </button>
                {functionSuggestions('')
                    .filter((f) => !compact && HELPER_FUNCTIONS.includes(f.fn))
                    .map((f) => (
                        <button
                            key={f.fn}
                            type="button"
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={() => typeAtCaret(`.${f.insert}`)}
                        >
                            .{f.insert}
                        </button>
                    ))}
            </div>
            {picking && (
                <FunctionPicker
                    onPick={(doc) => {
                        setPicking(false);
                        typeAtCaret(`@${doc.name}()`, 1);
                    }}
                />
            )}
            <div className="taw-expression-preview" aria-live="polite">
                <span className="taw-expression-preview__label">{__('Preview', 'taw-core')}</span>
                <span className="taw-expression-preview__value">
                    {value.trim() === '' ? (
                        <em>{__('Type @ to add a value, then . for a function.', 'taw-core')}</em>
                    ) : !showPreview ? (
                        <em>{__('Loading…', 'taw-core')}</em>
                    ) : renderPreview ? (
                        renderPreview(preview.result)
                    ) : preview.result.value === '' ? (
                        <em>{__('(empty for this post)', 'taw-core')}</em>
                    ) : (
                        preview.result.value
                    )}
                </span>
            </div>
            {problems.length > 0 && (
                <ul className="taw-expression-errors">
                    {problems.map((problem) => (
                        <li key={problem.key}>
                            <Dashicon icon="warning" />
                            <span>
                                {problem.text}
                                {problem.name && (
                                    <>
                                        {' '}
                                        <code>@{problem.name}</code>
                                    </>
                                )}
                                {problem.hint && (
                                    <>
                                        {' '}
                                        {__('Did you mean', 'taw-core')} <code>{`@${problem.hint}(…)`}</code>?
                                    </>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

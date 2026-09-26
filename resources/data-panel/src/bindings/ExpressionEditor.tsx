/**
 * The Expression tab's editor (ADR-0012): a plain textarea with `@` name
 * suggestions and `.` function suggestions, a live preview from the server
 * and errors from the TypeScript parser.
 */
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Dashicon } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { completionAt, parseExpression, type TokenPart } from './expression';
import { previewExpression } from './preview';
import { errorMessage, functionSuggestions, nameSuggestions, type ValueOption } from './tags';

interface Suggestion {
    key: string;
    label: string;
    detail: string;
    insert: string;
}

/** Names that aren't values the popup knows (after the parser's own errors). */
function unknownNames(tokens: TokenPart[], options: ValueOption[]): string[] {
    const known = new Set(options.map((o) => o.name));
    return tokens
        .filter((t) => !t.error)
        .map((t) => t.name)
        .filter((name) => !known.has(name) && !(name.startsWith('post.') && known.has(name.slice(5))));
}

export function ExpressionEditor({
    value,
    onChange,
    options,
    autoFocus = true,
}: {
    value: string;
    onChange: (next: string) => void;
    options: ValueOption[];
    autoFocus?: boolean;
}) {
    const ref = useRef<HTMLTextAreaElement>(null);
    const [caret, setCaret] = useState(value.length);
    const [active, setActive] = useState(0);
    // Suggestions open once the user types, not for a prefilled expression.
    const [dismissed, setDismissed] = useState(value !== '');
    // The preview and the expression it belongs to (a stale one isn't shown).
    const [preview, setPreview] = useState<{ for: string; value: string } | null>(null);

    const parsed = useMemo(() => parseExpression(value), [value]);
    const tokens = parsed.parts.filter((p): p is TokenPart => 'name' in p);
    const unknown = unknownNames(tokens, options);

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
        return nameSuggestions(options, completion.prefix).map((o) => ({
            key: o.key,
            label: `@${o.name}`,
            detail: `${o.group} › ${o.label}`,
            insert: o.name,
        }));
    }, [value, caret, options]);

    const open = !dismissed && suggestions.length > 0;

    // Debounced server preview.
    useEffect(() => {
        if (value.trim() === '') return undefined;
        let current = true;
        const timer = setTimeout(() => {
            void previewExpression(value).then((result) => {
                if (current) setPreview({ for: value, value: result.value });
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

    /** Type text at the caret (the helper chips). */
    const typeAtCaret = (text: string) => {
        const next = value.slice(0, caret) + text + value.slice(caret);
        const at = caret + text.length;
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
    const problems = [
        ...parsed.errors.map((error) => ({
            key: `${error.code}-${error.at}`,
            text: errorMessage(error.code),
            name: '',
        })),
        ...unknown.map((name) => ({
            key: `unknown-${name}`,
            text: __('Not a value this post has:', 'taw-core'),
            name,
        })),
    ];

    return (
        <div className="taw-expression-editor">
            <div className="taw-expression-field">
                <textarea
                    ref={ref}
                    aria-label={__('Expression', 'taw-core')}
                    rows={3}
                    autoFocus={autoFocus}
                    spellCheck={false}
                    value={value}
                    placeholder={__("Published on @post.date.format('F j, Y') by @post.author", 'taw-core')}
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
                {functionSuggestions('').map((f) => (
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
            <div className="taw-expression-preview" aria-live="polite">
                <span className="taw-expression-preview__label">{__('Preview', 'taw-core')}</span>
                <span className="taw-expression-preview__value">
                    {value.trim() === '' ? (
                        <em>{__('Type @ to add a value, then . for a function.', 'taw-core')}</em>
                    ) : !showPreview ? (
                        <em>{__('Loading…', 'taw-core')}</em>
                    ) : preview.value === '' ? (
                        <em>{__('(empty for this post)', 'taw-core')}</em>
                    ) : (
                        preview.value
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
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

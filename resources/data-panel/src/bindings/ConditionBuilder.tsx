/**
 * The rule builder (ADR-0013): "Show when [All/Any] of these are true", rows of
 * value · comparison · operand, one level of groups, and a live answer for the
 * edited post ("Shown for this post" / "Hidden for this post").
 */
import React, { useEffect, useMemo, useState } from 'react';
import { Button, Dashicon } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
    conditionErrorMessage,
    conditionErrors,
    DATE_OPERATORS,
    isGroup,
    MAX_RULES,
    newCondition,
    newRule,
    operatorGroups,
    operatorLabel,
    OPERATORS,
    ruleCount,
    withOperator,
    type Condition,
    type Group,
    type Rule,
} from './conditions';
import { previewCondition } from './preview';
import { groupOptions, type ValueOption } from './tags';

const CUSTOM = '__custom__';

function placeholderFor(op: string): string {
    if (DATE_OPERATORS.includes(op)) return __('today, -30 days or 2025-01-31', 'taw-core');
    if (['gt', 'gte', 'lt', 'lte', 'between'].includes(op)) return __('A number', 'taw-core');
    if (op === 'in' || op === 'not_in') return __('fiction, poetry', 'taw-core');
    return __('Text, or @another_value', 'taw-core');
}

function MatchSelect({ value, onChange }: { value: Group['match']; onChange: (match: 'all' | 'any') => void }) {
    return (
        <select
            className="taw-cond-match"
            aria-label={__('Match', 'taw-core')}
            value={value ?? 'all'}
            onChange={(e) => onChange(e.target.value === 'any' ? 'any' : 'all')}
        >
            <option value="all">{__('all', 'taw-core')}</option>
            <option value="any">{__('any', 'taw-core')}</option>
        </select>
    );
}

function RuleRow({
    rule,
    options,
    onChange,
    onRemove,
}: {
    rule: Rule;
    options: ValueOption[];
    onChange: (rule: Rule) => void;
    onRemove: () => void;
}) {
    const known = options.find((o) => `@${o.name}` === rule.value);
    const [custom, setCustom] = useState(!known && rule.value !== '');
    const groups = useMemo(() => groupOptions(options, ''), [options]);
    const arity = OPERATORS[rule.op] ?? 'one';
    const menus = operatorGroups(Boolean(known?.isDate));
    const listed = menus.some((m) => m.ops.includes(rule.op));
    const list = (to: Rule['to']) => (Array.isArray(to) ? to.join(', ') : String(to ?? ''));
    const pair = Array.isArray(rule.to) ? rule.to : [rule.to ?? '', ''];

    return (
        <div className="taw-cond-rule">
            <div className="taw-cond-rule__main">
                <select
                    aria-label={__('Value', 'taw-core')}
                    value={custom ? CUSTOM : (rule.value ?? '')}
                    onChange={(e) => {
                        if (e.target.value === CUSTOM) {
                            setCustom(true);
                            return;
                        }
                        setCustom(false);
                        const option = options.find((o) => `@${o.name}` === e.target.value);
                        // A date value starts on a date comparison; others keep theirs.
                        const op =
                            option?.isDate && !DATE_OPERATORS.includes(rule.op) && arity !== 'none' ? 'after' : rule.op;
                        onChange(withOperator({ ...rule, value: e.target.value }, op));
                    }}
                >
                    {!known && !custom && <option value="">{__('Pick a value…', 'taw-core')}</option>}
                    {groups.map(([group, items]) => (
                        <optgroup key={group} label={group}>
                            {items.map((o) => (
                                <option key={o.key} value={`@${o.name}`}>
                                    {o.label}
                                </option>
                            ))}
                        </optgroup>
                    ))}
                    <option value={CUSTOM}>{__('Custom value…', 'taw-core')}</option>
                </select>
                <Button icon="trash" size="small" label={__('Remove rule', 'taw-core')} onClick={onRemove} />
            </div>
            {custom && (
                <input
                    type="text"
                    className="taw-cond-input taw-cond-input--code"
                    aria-label={__('Custom value', 'taw-core')}
                    placeholder="@book_year.format('Y')"
                    value={rule.value}
                    onChange={(e) => onChange({ ...rule, value: e.target.value })}
                />
            )}
            <select
                aria-label={__('Comparison', 'taw-core')}
                value={rule.op}
                onChange={(e) => onChange(withOperator(rule, e.target.value))}
            >
                {menus.map((menu) => (
                    <optgroup key={menu.label} label={menu.label}>
                        {menu.ops.map((op) => (
                            <option key={op} value={op}>
                                {operatorLabel(op)}
                            </option>
                        ))}
                    </optgroup>
                ))}
                {!listed && <option value={rule.op}>{operatorLabel(rule.op)}</option>}
            </select>
            {arity === 'one' && (
                <input
                    type="text"
                    className="taw-cond-input"
                    aria-label={__('Compare with', 'taw-core')}
                    placeholder={placeholderFor(rule.op)}
                    value={String(rule.to ?? '')}
                    onChange={(e) => onChange({ ...rule, to: e.target.value })}
                />
            )}
            {arity === 'list' && (
                <input
                    type="text"
                    className="taw-cond-input"
                    aria-label={__('Items, separated by commas', 'taw-core')}
                    placeholder={placeholderFor(rule.op)}
                    value={list(rule.to)}
                    // Stored as typed ("a, b"): the server splits it, and a trailing comma survives typing.
                    onChange={(e) => onChange({ ...rule, to: e.target.value })}
                />
            )}
            {arity === 'pair' && (
                <div className="taw-cond-pair">
                    <input
                        type="text"
                        className="taw-cond-input"
                        aria-label={__('From', 'taw-core')}
                        placeholder={placeholderFor(rule.op)}
                        value={String(pair[0] ?? '')}
                        onChange={(e) => onChange({ ...rule, to: [e.target.value, pair[1] ?? ''] })}
                    />
                    <span>{__('and', 'taw-core')}</span>
                    <input
                        type="text"
                        className="taw-cond-input"
                        aria-label={__('To', 'taw-core')}
                        placeholder={placeholderFor(rule.op)}
                        value={String(pair[1] ?? '')}
                        onChange={(e) => onChange({ ...rule, to: [pair[0] ?? '', e.target.value] })}
                    />
                </div>
            )}
        </div>
    );
}

function RuleList({
    group,
    options,
    onChange,
    nested,
    canAdd,
}: {
    group: Group;
    options: ValueOption[];
    onChange: (group: Group) => void;
    nested: boolean;
    canAdd: boolean;
}) {
    const first = options[0] ? `@${options[0].name}` : '@post.title';
    const set = (i: number, next: Rule | Group) =>
        onChange({ ...group, rules: group.rules.map((r, j) => (j === i ? next : r)) });
    const remove = (i: number) => onChange({ ...group, rules: group.rules.filter((_r, j) => j !== i) });

    return (
        <>
            {group.rules.map((rule, i) =>
                isGroup(rule) ? (
                    <div className="taw-cond-group" key={i}>
                        <div className="taw-cond-group__head">
                            <span>
                                <MatchSelect value={rule.match} onChange={(match) => set(i, { ...rule, match })} />{' '}
                                {__('of these:', 'taw-core')}
                            </span>
                            <Button
                                icon="trash"
                                size="small"
                                label={__('Remove group', 'taw-core')}
                                onClick={() => remove(i)}
                            />
                        </div>
                        <RuleList
                            group={rule}
                            options={options}
                            onChange={(next) => set(i, next)}
                            nested
                            canAdd={canAdd}
                        />
                    </div>
                ) : (
                    <RuleRow
                        key={i}
                        rule={rule}
                        options={options}
                        onChange={(next) => set(i, next)}
                        onRemove={() => remove(i)}
                    />
                ),
            )}
            <div className="taw-cond-add">
                <Button
                    variant="tertiary"
                    size="small"
                    icon="plus-alt2"
                    disabled={!canAdd}
                    onClick={() => onChange({ ...group, rules: [...group.rules, newRule(first)] })}
                >
                    {__('Add rule', 'taw-core')}
                </Button>
                {!nested && (
                    <Button
                        variant="tertiary"
                        size="small"
                        icon="networking"
                        disabled={!canAdd}
                        onClick={() =>
                            onChange({ ...group, rules: [...group.rules, { match: 'any', rules: [newRule(first)] }] })
                        }
                    >
                        {__('Add group', 'taw-core')}
                    </Button>
                )}
            </div>
        </>
    );
}

/** Whether the condition holds for the edited post, debounced; stale answers aren't shown. */
export function useConditionAnswer(condition: Condition, enabled: boolean): { shown: boolean } | null {
    const json = JSON.stringify(condition);
    const [answer, setAnswer] = useState<{ for: string; shown: boolean } | null>(null);

    useEffect(() => {
        if (!enabled) return undefined;
        let current = true;
        const timer = setTimeout(() => {
            void previewCondition(JSON.parse(json)).then((result) => {
                if (current && result) setAnswer({ for: json, shown: result.shown });
            });
        }, 300);
        return () => {
            current = false;
            clearTimeout(timer);
        };
    }, [json, enabled]);

    return answer && answer.for === json ? { shown: answer.shown } : null;
}

export function ConditionBuilder({
    value,
    onChange,
    options,
    subject,
}: {
    value: Condition;
    onChange: (next: Condition) => void;
    options: ValueOption[];
    /** What is shown or hidden: "this block", "this value" (goes into "Show %s when"). */
    subject: string;
}) {
    const errors = conditionErrors(value);
    const answer = useConditionAnswer(value, errors.length === 0);
    const canAdd = ruleCount(value) < MAX_RULES;

    return (
        <div className="taw-cond">
            <p className="taw-cond__head">
                {/* translators: %s: what is shown, e.g. "this block". Followed by a menu: all / any. */}
                {sprintf(__('Show %s when', 'taw-core'), subject)}{' '}
                <MatchSelect value={value.match} onChange={(match) => onChange({ ...value, match })} />{' '}
                {__('of these are true:', 'taw-core')}
            </p>
            <RuleList group={value} options={options} onChange={onChange} nested={false} canAdd={canAdd} />
            {errors.length > 0 ? (
                <ul className="taw-expression-errors">
                    {errors.map((error) => (
                        <li key={`${error.code}|${error.path}`}>
                            <Dashicon icon="warning" />
                            {conditionErrorMessage(error)}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className={`taw-cond-answer${answer ? (answer.shown ? ' is-shown' : ' is-hidden') : ''}`}>
                    <Dashicon icon={answer?.shown === false ? 'hidden' : 'visibility'} />
                    {answer === null
                        ? __('Checking this post…', 'taw-core')
                        : answer.shown
                          ? __('Shown for this post', 'taw-core')
                          : __('Hidden for this post', 'taw-core')}
                </p>
            )}
        </div>
    );
}

/** A value's condition: `if` and the `else` text (chips and block text). */
export interface Conditional {
    if?: Condition;
    else?: string;
}

/**
 * "Show if…" for a value (a chip, block text): off until switched on, then the
 * builder and the text shown instead.
 */
export function ConditionSection({
    value,
    onChange,
    options,
}: {
    value: Conditional;
    onChange: (next: Conditional) => void;
    options: ValueOption[];
}) {
    const on = value.if !== undefined;
    const first = options[0] ? `@${options[0].name}` : '@post.title';

    return (
        <div className={`taw-cond-section${on ? ' is-on' : ''}`}>
            <button
                type="button"
                className="taw-cond-toggle"
                aria-expanded={on}
                onClick={() => onChange(on ? {} : { if: newCondition(first), else: value.else })}
            >
                <Dashicon icon={on ? 'visibility' : 'plus-alt2'} />
                <span>{on ? __('Shown only when…', 'taw-core') : __('Show only when…', 'taw-core')}</span>
                {on && <span className="taw-cond-toggle__off">{__('Remove condition', 'taw-core')}</span>}
            </button>
            {on && value.if && (
                <>
                    <ConditionBuilder
                        value={value.if}
                        onChange={(next) => onChange({ ...value, if: next })}
                        options={options}
                        subject={__('this value', 'taw-core')}
                    />
                    <label className="taw-cond-else">
                        <span>{__('Otherwise show', 'taw-core')}</span>
                        <input
                            type="text"
                            className="taw-cond-input"
                            placeholder={__('Nothing', 'taw-core')}
                            value={value.else ?? ''}
                            onChange={(e) => onChange({ ...value, else: e.target.value })}
                        />
                    </label>
                </>
            )}
        </div>
    );
}

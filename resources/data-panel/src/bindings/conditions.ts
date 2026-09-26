/**
 * Conditions (taw/core ADR-0013) in the editor: the shape, the same checks as
 * Bindings\Condition\Condition (tests/fixtures/conditions.json `shape` cases),
 * operator labels and defaults. The server evaluates; the editor previews
 * through `kind: "condition"`.
 */
import { __ } from '@wordpress/i18n';
import { parseExpression } from './expression';

export type Arity = 'none' | 'one' | 'list' | 'pair';

export interface Rule {
    value: string;
    op: string;
    to?: string | number | boolean | (string | number | boolean)[];
}

export interface Group {
    match?: 'all' | 'any';
    rules: (Rule | Group)[];
}

export type Condition = Group;

export interface ConditionError {
    code: string;
    path: string;
}

export const MAX_RULES = 20;
export const MAX_DEPTH = 2;
export const MAX_LIST = 50;

/** Operator → what `to` takes (Condition::OPERATORS). */
export const OPERATORS: Record<string, Arity> = {
    empty: 'none',
    not_empty: 'none',
    is_true: 'none',
    is_false: 'none',
    equals: 'one',
    not_equals: 'one',
    contains: 'one',
    not_contains: 'one',
    starts_with: 'one',
    ends_with: 'one',
    has: 'one',
    has_not: 'one',
    gt: 'one',
    gte: 'one',
    lt: 'one',
    lte: 'one',
    before: 'one',
    after: 'one',
    on: 'one',
    in: 'list',
    not_in: 'list',
    between: 'pair',
    between_dates: 'pair',
};

export const DATE_OPERATORS = ['before', 'after', 'on', 'between_dates'];

export function isGroup(rule: Rule | Group): rule is Group {
    return typeof rule === 'object' && rule !== null && 'rules' in rule;
}

/** A token operand (`@x`), not text (`@@x`). */
export function isToken(operand: string): boolean {
    return operand.startsWith('@') && !operand.startsWith('@@');
}

/** One valid expression token, nothing around it. */
export function isValidToken(token: string): boolean {
    if (!token.startsWith('@')) return false;
    const { parts, errors } = parseExpression(token);
    return errors.length === 0 && parts.length === 1 && 'name' in parts[0] && !parts[0].error;
}

function join(path: string, key: string): string {
    return path === '' ? key : `${path}.${key}`;
}

function operand(value: unknown): boolean {
    if (typeof value === 'boolean' || typeof value === 'number') return true;
    if (typeof value !== 'string') return false;
    return !(isToken(value) && !isValidToken(value.trim()));
}

function validTo(arity: Arity, to: unknown): boolean {
    if (arity === 'none') return true;
    if (arity === 'one') return operand(to);
    let list = to;
    if (arity === 'list' && typeof to === 'string' && !isToken(to)) {
        list = to
            .split(',')
            .map((s) => s.trim())
            .filter((s) => s !== '');
    }
    if (!Array.isArray(list) || list.length === 0) return false;
    if ((arity === 'pair' && list.length !== 2) || list.length > MAX_LIST) return false;
    return list.every(operand);
}

/** The errors Condition::normalize() would report (same codes and paths). */
export function conditionErrors(condition: unknown): ConditionError[] {
    const errors: ConditionError[] = [];
    let count = 0;

    const rule = (value: unknown, path: string) => {
        if (!value || typeof value !== 'object' || Array.isArray(value)) {
            errors.push({ code: 'invalid', path });
            return;
        }
        const r = value as Record<string, unknown>;
        if (typeof r.value !== 'string' || !isValidToken(r.value.trim())) {
            errors.push({ code: 'invalid_value', path: `${path}.value` });
        }
        if (typeof r.op !== 'string' || !(r.op in OPERATORS)) {
            errors.push({ code: 'unknown_op', path: `${path}.op` });
            return;
        }
        if (!validTo(OPERATORS[r.op], r.to)) {
            errors.push({ code: 'invalid_to', path: `${path}.to` });
        }
    };

    const group = (value: unknown, path: string, depth: number) => {
        const g = value as Record<string, unknown> | null;
        if (!g || typeof g !== 'object' || Array.isArray(g) || !Array.isArray(g.rules)) {
            errors.push({ code: 'invalid', path: join(path, 'rules') });
            return;
        }
        const match = g.match ?? 'all';
        if (match !== 'all' && match !== 'any') {
            errors.push({ code: 'bad_match', path: join(path, 'match') });
        }
        g.rules.forEach((item: unknown, i: number) => {
            const itemPath = join(path, `rules.${i}`);
            if (item && typeof item === 'object' && !Array.isArray(item) && 'rules' in item) {
                if (depth >= MAX_DEPTH) {
                    errors.push({ code: 'too_deep', path: itemPath });
                    return;
                }
                group(item, itemPath, depth + 1);
                return;
            }
            count++;
            rule(item, itemPath);
        });
    };

    group(condition, '', 1);
    if (count > MAX_RULES) errors.push({ code: 'too_many_rules', path: 'rules' });
    return errors;
}

/** How many rules (not groups) a condition has. */
export function ruleCount(condition: Group): number {
    return condition.rules.reduce((n, r) => n + (isGroup(r) ? ruleCount(r) : 1), 0);
}

/** What `to` becomes when a rule switches to an operator. */
export function toFor(op: string, previous: Rule['to']): Rule['to'] {
    const arity = OPERATORS[op] ?? 'one';
    const first = Array.isArray(previous) ? previous[0] : previous;
    if (arity === 'none') return undefined;
    if (arity === 'one') return typeof first === 'string' || typeof first === 'number' ? first : '';
    if (arity === 'list')
        return Array.isArray(previous) ? previous : first !== undefined && first !== '' ? [first] : [];
    return Array.isArray(previous) && previous.length === 2 ? previous : [first ?? '', ''];
}

/** A rule with its operator changed, `to` reshaped for it. */
export function withOperator(rule: Rule, op: string): Rule {
    const next: Rule = { value: rule.value, op };
    const to = toFor(op, rule.to);
    if (to !== undefined) next.to = to;
    return next;
}

export function newRule(value: string): Rule {
    return { value, op: 'not_empty' };
}

export function newCondition(value: string): Condition {
    return { match: 'all', rules: [newRule(value)] };
}

/** A condition from stored JSON (a chip's `if`, a block's `tawShowIf`), or null. */
export function readCondition(value: unknown): Condition | null {
    return value && typeof value === 'object' && !Array.isArray(value) && Array.isArray((value as Group).rules)
        ? (value as Condition)
        : null;
}

/** Operators by kind, for the operator menu; date values get the date ones first. */
export function operatorGroups(isDate: boolean): { label: string; ops: string[] }[] {
    const presence = { label: __('Presence', 'taw-core'), ops: ['not_empty', 'empty'] };
    const text = {
        label: __('Text', 'taw-core'),
        ops: ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with'],
    };
    const lists = { label: __('Lists', 'taw-core'), ops: ['in', 'not_in', 'has', 'has_not'] };
    const numbers = { label: __('Numbers', 'taw-core'), ops: ['gt', 'gte', 'lt', 'lte', 'between'] };
    const dates = { label: __('Dates', 'taw-core'), ops: ['after', 'before', 'on', 'between_dates'] };
    const yesNo = { label: __('Yes / no', 'taw-core'), ops: ['is_true', 'is_false'] };
    return isDate ? [presence, dates, text] : [presence, text, lists, numbers, dates, yesNo];
}

export function operatorLabel(op: string): string {
    switch (op) {
        case 'empty':
            return __('is empty', 'taw-core');
        case 'not_empty':
            return __('is not empty', 'taw-core');
        case 'is_true':
            return __('is yes / on', 'taw-core');
        case 'is_false':
            return __('is no / off', 'taw-core');
        case 'equals':
            return __('is', 'taw-core');
        case 'not_equals':
            return __('is not', 'taw-core');
        case 'contains':
            return __('contains', 'taw-core');
        case 'not_contains':
            return __('does not contain', 'taw-core');
        case 'starts_with':
            return __('starts with', 'taw-core');
        case 'ends_with':
            return __('ends with', 'taw-core');
        case 'has':
            return __('has the item', 'taw-core');
        case 'has_not':
            return __('does not have the item', 'taw-core');
        case 'in':
            return __('is one of', 'taw-core');
        case 'not_in':
            return __('is not one of', 'taw-core');
        case 'gt':
            return __('is greater than', 'taw-core');
        case 'gte':
            return __('is at least', 'taw-core');
        case 'lt':
            return __('is less than', 'taw-core');
        case 'lte':
            return __('is at most', 'taw-core');
        case 'between':
            return __('is between', 'taw-core');
        case 'before':
            return __('is before', 'taw-core');
        case 'after':
            return __('is after', 'taw-core');
        case 'on':
            return __('is on', 'taw-core');
        case 'between_dates':
            return __('is between the dates', 'taw-core');
        default:
            return op;
    }
}

/** A condition error as a message ("Rule 2: …"). */
export function conditionErrorMessage(error: ConditionError): string {
    const where = /^rules\.(\d+)(?:\.rules\.(\d+))?/.exec(error.path);
    const prefix = where
        ? where[2] !== undefined
            ? `${__('Group', 'taw-core')} ${Number(where[1]) + 1}, ${__('rule', 'taw-core')} ${Number(where[2]) + 1}: `
            : `${__('Rule', 'taw-core')} ${Number(where[1]) + 1}: `
        : '';
    switch (error.code) {
        case 'invalid_value':
            return prefix + __('pick a value, or write one value like @book_year.', 'taw-core');
        case 'unknown_op':
            return prefix + __('pick a comparison.', 'taw-core');
        case 'invalid_to':
            return prefix + __('fill in what to compare with.', 'taw-core');
        case 'too_many_rules':
            return __('Too many rules (20 at most).', 'taw-core');
        case 'too_deep':
            return prefix + __('groups can’t contain groups.', 'taw-core');
        default:
            return prefix + __('this rule is incomplete.', 'taw-core');
    }
}

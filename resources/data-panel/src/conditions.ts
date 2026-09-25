import type { Condition } from './types';

/**
 * The value as a form would post it, so conditions match the metabox's and
 * the server's (TAW\Core\DataPanel\Validation::conditionsMet).
 */
export function asPosted(value: unknown): string {
    if (value === true) return '1';
    if (value === false || value === null || value === undefined) return '';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

/** Same operators and AND logic as Metabox::evaluate_conditions(). */
export function conditionsMet(conditions: Condition[] | undefined, values: Record<string, unknown>): boolean {
    return (conditions ?? []).every((condition) => {
        const actual = asPosted(values[condition.field]);
        const expected = asPosted(condition.value);
        switch (condition.operator) {
            case '!=':
                return actual != expected;
            case 'contains':
                return actual.includes(expected);
            case 'empty':
                return isEmpty(actual);
            case '!empty':
                return !isEmpty(actual);
            default:
                return actual == expected;
        }
    });
}

/** PHP's empty() on a posted string: '' and '0'. */
function isEmpty(value: string): boolean {
    return value === '' || value === '0';
}

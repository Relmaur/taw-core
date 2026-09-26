/**
 * The expression grammar (taw/core ADR-0012), in TypeScript: the editor parses
 * only to offer autocomplete and flag errors. PHP (Bindings\Expression\Parser)
 * evaluates. Both pass tests/fixtures/expressions.json, so they can't drift.
 *
 *   Published on @book_date.format('F j, Y') by @post.author · @option.phone.default('—')
 *
 * Offsets count characters (code points), as in PHP.
 */

export interface ExpressionCall {
    fn: string;
    args: (string | number)[];
}

export interface TextPart {
    text: string;
}

export interface TokenPart {
    name: string;
    calls: ExpressionCall[];
    at: number;
    length: number;
    error?: string;
}

export type Part = TextPart | TokenPart;

export interface ExpressionError {
    code: string;
    at: number;
}

export interface Parsed {
    parts: Part[];
    errors: ExpressionError[];
}

export const MAX_LENGTH = 500;
export const MAX_TOKENS = 20;
export const NAMESPACES = ['post', 'site', 'option', 'term', 'author'];
export const SITE_PROPERTIES = ['name', 'tagline', 'url', 'year'];

/** Function name → its argument kinds (Parser::FUNCTIONS). */
export const FUNCTIONS: Record<string, ('string' | 'int')[]> = {
    format: ['string'],
    upper: [],
    lower: [],
    default: ['string'],
    truncate: ['int'],
};

const IDENT_START = /[A-Za-z_]/;
const IDENT_CHAR = /[A-Za-z0-9_]/;

function identAt(chars: string[], i: number): string | null {
    if (!IDENT_START.test(chars[i] ?? '')) return null;
    let end = i + 1;
    while (end < chars.length && IDENT_CHAR.test(chars[end])) end++;
    return chars.slice(i, end).join('');
}

function startsToken(chars: string[], i: number): boolean {
    const before = i > 0 ? chars[i - 1] : ' ';
    return !IDENT_CHAR.test(before) && identAt(chars, i + 1) !== null;
}

/** Arguments after `(`, up to and including `)`: the values and the index after `)`, or null. */
function argumentsAt(chars: string[], start: number): [(string | number)[], number] | null {
    const values: (string | number)[] = [];
    let i = start;
    const skip = () => {
        while (i < chars.length && (chars[i] === ' ' || chars[i] === '\t')) i++;
    };

    skip();
    if (chars[i] === ')') return [[], i + 1];

    while (i < chars.length) {
        skip();
        const char = chars[i] ?? '';
        if (char === "'" || char === '"') {
            let value = '';
            i++;
            while (i < chars.length && chars[i] !== char) {
                if (chars[i] === '\\' && i + 1 < chars.length) i++;
                value += chars[i];
                i++;
            }
            if (i >= chars.length) return null;
            i++;
            values.push(value);
        } else {
            const match = /^-?\d+(\.\d+)?/.exec(chars.slice(i, i + 32).join(''));
            if (!match) return null;
            values.push(match[0].includes('.') ? parseFloat(match[0]) : parseInt(match[0], 10));
            i += match[0].length;
        }

        skip();
        if (chars[i] === ')') return [values, i + 1];
        if (chars[i] !== ',') return null;
        i++;
    }
    return null;
}

function checkCall(fn: string, args: (string | number)[]): string | null {
    const kinds = FUNCTIONS[fn];
    if (!kinds) return 'unknown_function';
    if (args.length !== kinds.length) return 'wrong_arguments';
    const ok = kinds.every((kind, k) =>
        kind === 'string' ? typeof args[k] === 'string' : Number.isInteger(args[k]) && (args[k] as number) > 0,
    );
    return ok ? null : 'wrong_arguments';
}

function tokenAt(chars: string[], at: number): TokenPart & { errorAt?: number } {
    let i = at + 1;
    let name = identAt(chars, i) as string;
    i += name.length;

    if (NAMESPACES.includes(name) && chars[i] === '.') {
        const rest = identAt(chars, i + 1);
        if (rest !== null) {
            name += `.${rest}`;
            i += 1 + rest.length;
        }
    }

    const token: TokenPart & { errorAt?: number } = { name, calls: [], at, length: 0 };
    const fail = (code: string, where: number) => {
        if (token.error === undefined) {
            token.error = code;
            token.errorAt = where;
        }
    };

    while (chars[i] === '.') {
        const fn = identAt(chars, i + 1);
        if (fn === null || chars[i + 1 + fn.length] !== '(') break;
        const fnAt = i + 1;
        i += 2 + fn.length;
        const args = argumentsAt(chars, i);
        if (args === null) {
            fail('bad_arguments', fnAt);
            token.length = i - at;
            return token;
        }
        [, i] = args;
        token.calls.push({ fn, args: args[0] });
        const error = checkCall(fn, args[0]);
        if (error !== null) fail(error, fnAt);
    }

    if (name === 'site' || (name.startsWith('site.') && !SITE_PROPERTIES.includes(name.slice(5)))) {
        fail('unknown_name', at);
    }

    token.length = i - at;
    return token;
}

/** Same result as Bindings\Expression\Parser::parse(), key order included. */
export function parseExpression(expression: string): Parsed {
    const chars = Array.from(expression);
    if (chars.length > MAX_LENGTH) return { parts: [], errors: [{ code: 'too_long', at: 0 }] };

    const parts: Part[] = [];
    const errors: ExpressionError[] = [];
    let text = '';
    let tokens = 0;
    let i = 0;

    while (i < chars.length) {
        if (chars[i] === '@' && chars[i + 1] === '@') {
            text += '@';
            i += 2;
            continue;
        }
        if (chars[i] === '@' && startsToken(chars, i)) {
            const token = tokenAt(chars, i);
            if (text !== '') {
                parts.push({ text });
                text = '';
            }
            if (++tokens > MAX_TOKENS) return { parts: [], errors: [{ code: 'too_many_tokens', at: i }] };

            const shaped: TokenPart = { name: token.name, calls: token.calls, at: token.at, length: token.length };
            if (token.error !== undefined) {
                shaped.error = token.error;
                errors.push({ code: token.error, at: token.errorAt ?? token.at });
            }
            parts.push(shaped);
            i += token.length;
            continue;
        }
        text += chars[i];
        i++;
    }

    if (text !== '') parts.push({ text });
    return { parts, errors };
}

/** What the editor is completing at the caret: a name after `@`, a function after `name.`, or nothing. */
export type Completion =
    { kind: 'name'; prefix: string; start: number } | { kind: 'function'; prefix: string; start: number } | null;

export function completionAt(before: string): Completion {
    const chars = Array.from(before);
    const text = chars.join('');
    const fnMatch =
        /(^|[^A-Za-z0-9_@])@[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?((?:\.[A-Za-z_][A-Za-z0-9_]*\([^()]*\))*)\.([A-Za-z_]*)$/.exec(
            text,
        );
    if (fnMatch) {
        const [, lead, second] = fnMatch;
        // `@post.` + letters is still the name (a namespace), not a function.
        const nameOnly = fnMatch[3] === '' && second === undefined;
        const first = /@([A-Za-z_][A-Za-z0-9_]*)/.exec(fnMatch[0].slice(lead.length))?.[1] ?? '';
        if (!(nameOnly && NAMESPACES.includes(first))) {
            return { kind: 'function', prefix: fnMatch[4], start: chars.length - Array.from(fnMatch[4]).length };
        }
    }
    const nameMatch = /(^|[^A-Za-z0-9_@])@([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_]*)?)?$/.exec(text);
    if (nameMatch) {
        const prefix = nameMatch[2] ?? '';
        return { kind: 'name', prefix, start: chars.length - Array.from(prefix).length };
    }
    return null;
}

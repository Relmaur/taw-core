/**
 * The expression grammar (taw/core ADR-0012, v2: ADR-0015), in TypeScript:
 * the editor parses only to offer autocomplete and flag errors. PHP
 * (Bindings\Expression\Parser + Formula) evaluates. Both pass
 * tests/fixtures/expressions.json, so they can't drift.
 *
 *   Published on @book_date.format('F j, Y') by @post.author · @option.phone.default('—')
 *   Item @(@loop.index + 1) · @if(@stock > 0, 'In stock', 'Sold out')
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

/** A formula's tree (Formula.php's nodes, same keys in the same order). */
export type ExpressionNode =
    | { type: 'number'; value: number }
    | { type: 'text'; value: string }
    | { type: 'bool'; value: boolean }
    | { type: 'ref'; name: string }
    | { type: 'call'; fn: string; args: ExpressionNode[] }
    | { type: 'binary'; op: string; left: ExpressionNode; right: ExpressionNode }
    | { type: 'unary'; op: '-' | 'not'; arg: ExpressionNode };

/** A v2 token: `@( … )` or `@fn( … )`; `expr` is null when it doesn't parse. */
export interface FormulaPart {
    expr: ExpressionNode | null;
    at: number;
    length: number;
    error?: string;
}

export type Part = TextPart | TokenPart | FormulaPart;

export interface ExpressionError {
    code: string;
    at: number;
}

export interface Parsed {
    parts: Part[];
    errors: ExpressionError[];
}

export const MAX_LENGTH = 1000;
export const MAX_TOKENS = 50;
export const MAX_DEPTH = 10;
export const NAMESPACES = ['post', 'site', 'option', 'term', 'author', 'viewer', 'date', 'row', 'loop'];
export const SITE_PROPERTIES = ['name', 'tagline', 'url', 'year'];

/** Literal checks: `string` must be quoted, `int` a whole number above zero; `list`/`date` tell PHP how to read a value. */
export type ParamKind = 'any' | 'string' | 'int' | 'list' | 'date';

/** Parameter kinds (receiver first), how many are required, and whether the last repeats. */
export interface Signature {
    params: ParamKind[];
    required: number;
    variadic?: boolean;
}

/** The built-in functions (Functions::SIGNATURES). */
export const SIGNATURES: Record<string, Signature> = {
    format: { params: ['date', 'string'], required: 2 },
    upper: { params: ['any'], required: 1 },
    lower: { params: ['any'], required: 1 },
    capitalize: { params: ['any'], required: 1 },
    default: { params: ['any', 'string'], required: 2 },
    truncate: { params: ['any', 'int'], required: 2 },
    words: { params: ['any', 'int'], required: 2 },
    word_count: { params: ['any'], required: 1 },
    replace: { params: ['any', 'any', 'any'], required: 3 },
    strip: { params: ['any'], required: 1 },
    slug: { params: ['any'], required: 1 },
    urlencode: { params: ['any'], required: 1 },
    trim: { params: ['any'], required: 1 },
    plural: { params: ['any', 'any', 'any'], required: 3 },
    concat: { params: ['any'], required: 1, variadic: true },
    if: { params: ['any', 'any', 'any'], required: 2 },
    coalesce: { params: ['any'], required: 1, variadic: true },
    empty: { params: ['any'], required: 1 },
    round: { params: ['any', 'any'], required: 1 },
    floor: { params: ['any'], required: 1 },
    ceil: { params: ['any'], required: 1 },
    abs: { params: ['any'], required: 1 },
    min: { params: ['list'], required: 1, variadic: true },
    max: { params: ['list'], required: 1, variadic: true },
    number: { params: ['any', 'any'], required: 1 },
    currency: { params: ['any', 'string'], required: 1 },
    percent: { params: ['any', 'any'], required: 1 },
    ago: { params: ['date'], required: 1 },
    until: { params: ['date'], required: 1 },
    days_between: { params: ['date', 'date'], required: 2 },
    add_days: { params: ['date', 'any'], required: 2 },
    year: { params: ['date'], required: 1 },
    month: { params: ['date'], required: 1 },
    day: { params: ['date'], required: 1 },
    weekday: { params: ['date'], required: 1 },
    terms: { params: ['string'], required: 1 },
    column: { params: ['list', 'any'], required: 2 },
    count: { params: ['list'], required: 1 },
    join: { params: ['list', 'any'], required: 1 },
    first: { params: ['list'], required: 1 },
    last: { params: ['list'], required: 1 },
    sort: { params: ['list'], required: 1 },
    reverse: { params: ['list'], required: 1 },
    contains: { params: ['list', 'any'], required: 2 },
    sum: { params: ['list'], required: 1 },
    avg: { params: ['list'], required: 1 },
};

/** A site's own function, from PHP's `taw_expression_functions` (no callback: PHP runs it). */
export interface SiteFunction extends Signature {
    label: string;
    description: string;
}

let known: Record<string, Signature> = SIGNATURES;
let site: Record<string, SiteFunction> = {};

/** The site's own functions (window.tawBindings.functions); the built-ins keep their names. */
export function registerFunctions(functions: Record<string, SiteFunction> | unknown[] | undefined): void {
    site = functions && !Array.isArray(functions) ? functions : {};
    known = { ...site, ...SIGNATURES };
}

/** The site's own functions, as registered. */
export function siteFunctions(): Record<string, SiteFunction> {
    return site;
}

/** Functions::check(): 'wrong_arguments' or null; only literals are kind-checked. */
export function checkArguments(signature: Signature, args: ExpressionNode[]): string | null {
    const { params } = signature;
    const max = signature.variadic ? Infinity : params.length;
    if (args.length < signature.required || args.length > max) return 'wrong_arguments';
    for (let k = 0; k < args.length; k++) {
        const kind = params[Math.min(k, params.length - 1)];
        const arg = args[k];
        if (kind === 'string' && (arg.type === 'number' || arg.type === 'bool')) return 'wrong_arguments';
        if (
            kind === 'int' &&
            (arg.type === 'text' ||
                arg.type === 'bool' ||
                (arg.type === 'number' && !(Number.isInteger(arg.value) && arg.value >= 1)))
        )
            return 'wrong_arguments';
    }
    return null;
}

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
    return !IDENT_CHAR.test(before) && (chars[i + 1] === '(' || identAt(chars, i + 1) !== null);
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

/** A v1 method call: its literal arguments after the receiver. */
function checkCall(fn: string, args: (string | number)[], functions: Record<string, Signature>): string | null {
    const signature = functions[fn];
    if (!signature) return 'unknown_function';
    const nodes: ExpressionNode[] = [
        { type: 'ref', name: '' },
        ...args.map((arg): ExpressionNode =>
            typeof arg === 'string' ? { type: 'text', value: arg } : { type: 'number', value: arg },
        ),
    ];
    return checkArguments(signature, nodes);
}

class FormulaError extends Error {
    constructor(
        readonly code: string,
        readonly at: number,
    ) {
        super(code);
    }
}

const COMPARISONS = ['==', '!=', '<=', '>=', '<', '>'];
const SPACE = [' ', '\t', '\n', '\r'];

/** Formula.php: `@( … )` and `@fn( … )` tokens, recursive descent over characters. */
class Formula {
    i = 0;
    depth = 0;

    constructor(
        readonly chars: string[],
        readonly functions: Record<string, Signature>,
    ) {}

    static startsAt(chars: string[], at: number, functions: Record<string, Signature>): boolean {
        if (chars[at + 1] === '(') return true;
        const name = identAt(chars, at + 1);
        return name !== null && chars[at + 1 + name.length] === '(' && Object.hasOwn(functions, name);
    }

    static token(
        chars: string[],
        at: number,
        functions: Record<string, Signature>,
    ): { expr: ExpressionNode | null; end: number; error?: string; errorAt?: number } {
        const p = new Formula(chars, functions);
        p.i = at + 1;
        try {
            let node: ExpressionNode;
            if (chars[p.i] === '(') {
                p.i++;
                p.enter();
                node = p.expression();
                p.expect(')');
                p.depth--;
            } else {
                const name = identAt(chars, p.i) as string;
                const nameAt = p.i;
                p.i += name.length + 1;
                node = p.call(name, nameAt, []);
            }
            return { expr: p.methods(node), end: p.i };
        } catch (e) {
            if (!(e instanceof FormulaError)) throw e;
            return { expr: null, end: Formula.extent(chars, at), error: e.code, errorAt: e.at };
        }
    }

    static extent(chars: string[], at: number): number {
        let i = at + 1;
        const name = identAt(chars, i);
        if (name !== null) i += name.length;
        if (chars[i] !== '(') return i;
        i = Formula.balanced(chars, i);
        for (;;) {
            if (chars[i] !== '.') return i;
            const fn = identAt(chars, i + 1);
            if (fn === null || chars[i + 1 + fn.length] !== '(') return i;
            i = Formula.balanced(chars, i + 1 + fn.length);
        }
    }

    static balanced(chars: string[], start: number): number {
        let depth = 0;
        for (let i = start; i < chars.length; i++) {
            const char = chars[i];
            if (char === "'" || char === '"') {
                for (i++; i < chars.length && chars[i] !== char; i++) {
                    if (chars[i] === '\\') i++;
                }
                if (i >= chars.length) return chars.length;
            } else if (char === '(') {
                depth++;
            } else if (char === ')' && --depth === 0) {
                return i + 1;
            }
        }
        return chars.length;
    }

    expression(): ExpressionNode {
        let left = this.conjunction();
        while (this.keyword('or')) left = { type: 'binary', op: 'or', left, right: this.conjunction() };
        return left;
    }

    conjunction(): ExpressionNode {
        let left = this.negation();
        while (this.keyword('and')) left = { type: 'binary', op: 'and', left, right: this.negation() };
        return left;
    }

    negation(): ExpressionNode {
        if (this.keyword('not')) return { type: 'unary', op: 'not', arg: this.negation() };
        return this.comparison();
    }

    comparison(): ExpressionNode {
        const left = this.sum();
        this.skip();
        for (const op of COMPARISONS) {
            if (this.chars.slice(this.i, this.i + op.length).join('') === op) {
                this.i += op.length;
                return { type: 'binary', op, left, right: this.sum() };
            }
        }
        return left;
    }

    sum(): ExpressionNode {
        let left = this.product();
        for (;;) {
            this.skip();
            const op = this.chars[this.i];
            if (op !== '+' && op !== '-') return left;
            this.i++;
            left = { type: 'binary', op, left, right: this.product() };
        }
    }

    product(): ExpressionNode {
        let left = this.unary();
        for (;;) {
            this.skip();
            const op = this.chars[this.i];
            if (op !== '*' && op !== '/' && op !== '%') return left;
            this.i++;
            left = { type: 'binary', op, left, right: this.unary() };
        }
    }

    unary(): ExpressionNode {
        this.skip();
        if (this.chars[this.i] === '-') {
            this.i++;
            return { type: 'unary', op: '-', arg: this.unary() };
        }
        return this.methods(this.primary());
    }

    primary(): ExpressionNode {
        this.skip();
        const char = this.chars[this.i] ?? '';

        if (char === '(') {
            this.i++;
            this.enter();
            const node = this.expression();
            this.expect(')');
            this.depth--;
            return node;
        }
        if (char === "'" || char === '"') return { type: 'text', value: this.quoted() };
        const num = /^\d+(\.\d+)?/.exec(this.chars.slice(this.i, this.i + 64).join(''));
        if (num) {
            this.i += num[0].length;
            return { type: 'number', value: num[0].includes('.') ? parseFloat(num[0]) : parseInt(num[0], 10) };
        }

        const at = this.i;
        const sigil = char === '@';
        if (sigil) {
            this.i++;
            if (this.chars[this.i] === '(') return this.primary();
        }
        const name = identAt(this.chars, this.i);
        if (name === null) throw new FormulaError('bad_formula', at);
        const nameAt = this.i;
        this.i += name.length;

        if (this.chars[this.i] === '(') {
            this.i++;
            return this.call(name, nameAt, []);
        }
        if (!sigil) {
            if (name === 'true' || name === 'false') return { type: 'bool', value: name === 'true' };
            throw new FormulaError('bad_formula', at);
        }
        return this.reference(name, at);
    }

    reference(start: string, at: number): ExpressionNode {
        let name = start;
        // `@post.title` is a namespaced name; `@date.upper()` is a field named "date" with a call.
        if (NAMESPACES.includes(name) && this.chars[this.i] === '.') {
            const rest = identAt(this.chars, this.i + 1);
            if (rest !== null && this.chars[this.i + 1 + rest.length] !== '(') {
                name += `.${rest}`;
                this.i += 1 + rest.length;
            }
        }
        if (name === 'site' || (name.startsWith('site.') && !SITE_PROPERTIES.includes(name.slice(5)))) {
            throw new FormulaError('unknown_name', at);
        }
        return { type: 'ref', name };
    }

    call(fn: string, nameAt: number, receiver: ExpressionNode[]): ExpressionNode {
        const signature = Object.hasOwn(this.functions, fn) ? this.functions[fn] : undefined;
        if (!signature) throw new FormulaError('unknown_function', nameAt);
        this.enter();
        const args = [...receiver, ...this.arguments()];
        this.depth--;
        if (checkArguments(signature, args) !== null) throw new FormulaError('wrong_arguments', nameAt);
        return { type: 'call', fn, args };
    }

    methods(start: ExpressionNode): ExpressionNode {
        let node = start;
        for (;;) {
            if (this.chars[this.i] !== '.') return node;
            const fn = identAt(this.chars, this.i + 1);
            if (fn === null || this.chars[this.i + 1 + fn.length] !== '(') return node;
            const nameAt = this.i + 1;
            this.i += 2 + fn.length;
            node = this.call(fn, nameAt, [node]);
        }
    }

    arguments(): ExpressionNode[] {
        this.skip();
        if (this.chars[this.i] === ')') {
            this.i++;
            return [];
        }
        const args: ExpressionNode[] = [];
        for (;;) {
            args.push(this.expression());
            this.skip();
            const char = this.chars[this.i];
            if (char === ')') {
                this.i++;
                return args;
            }
            if (char !== ',') throw new FormulaError('bad_formula', this.i);
            this.i++;
        }
    }

    quoted(): string {
        const at = this.i;
        const quote = this.chars[this.i++];
        let value = '';
        while (this.i < this.chars.length && this.chars[this.i] !== quote) {
            if (this.chars[this.i] === '\\' && this.i + 1 < this.chars.length) this.i++;
            value += this.chars[this.i++];
        }
        if (this.i >= this.chars.length) throw new FormulaError('bad_formula', at);
        this.i++;
        return value;
    }

    keyword(word: string): boolean {
        this.skip();
        if (this.chars.slice(this.i, this.i + word.length).join('') !== word) return false;
        if (IDENT_CHAR.test(this.chars[this.i + word.length] ?? '')) return false;
        this.i += word.length;
        return true;
    }

    expect(char: string): void {
        this.skip();
        if (this.chars[this.i] !== char) throw new FormulaError('bad_formula', this.i);
        this.i++;
    }

    enter(): void {
        if (++this.depth > MAX_DEPTH) throw new FormulaError('too_deep', this.i);
    }

    skip(): void {
        while (this.i < this.chars.length && SPACE.includes(this.chars[this.i])) this.i++;
    }
}

function tokenAt(chars: string[], at: number, functions: Record<string, Signature>): TokenPart & { errorAt?: number } {
    let i = at + 1;
    let name = identAt(chars, i) as string;
    i += name.length;

    // `@post.title` is a namespaced name; `@date.upper()` is a field named "date" with a call.
    if (NAMESPACES.includes(name) && chars[i] === '.') {
        const rest = identAt(chars, i + 1);
        if (rest !== null && chars[i + 1 + rest.length] !== '(') {
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
        const error = checkCall(fn, args[0], functions);
        if (error !== null) fail(error, fnAt);
    }

    if (name === 'site' || (name.startsWith('site.') && !SITE_PROPERTIES.includes(name.slice(5)))) {
        fail('unknown_name', at);
    }

    token.length = i - at;
    return token;
}

/** Same result as Bindings\Expression\Parser::parse(), key order included. */
export function parseExpression(expression: string, functions: Record<string, Signature> = known): Parsed {
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
            if (text !== '') {
                parts.push({ text });
                text = '';
            }
            if (++tokens > MAX_TOKENS) return { parts: [], errors: [{ code: 'too_many_tokens', at: i }] };

            if (Formula.startsAt(chars, i, functions)) {
                const formula = Formula.token(chars, i, functions);
                const part: FormulaPart = { expr: formula.expr, at: i, length: formula.end - i };
                if (formula.error !== undefined) {
                    part.error = formula.error;
                    errors.push({ code: formula.error, at: formula.errorAt ?? i });
                }
                parts.push(part);
                i = formula.end;
                continue;
            }

            const token = tokenAt(chars, i, functions);

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

/** Every value name an expression reads, formulas included (for "not a value this post has"). */
export function namesIn(parsed: Parsed): string[] {
    const names: string[] = [];
    const walk = (node: ExpressionNode | null): void => {
        if (node === null) return;
        if (node.type === 'ref') names.push(node.name);
        else if (node.type === 'call') node.args.forEach(walk);
        else if (node.type === 'binary') {
            walk(node.left);
            walk(node.right);
        } else if (node.type === 'unary') walk(node.arg);
    };
    for (const part of parsed.parts) {
        if ('name' in part && !part.error) names.push(part.name);
        else if ('expr' in part) walk(part.expr);
    }
    return names;
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

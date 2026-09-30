<?php

declare(strict_types=1);

namespace TAW\Core\Bindings\Expression;

// No ABSPATH guard: pure class (no WordPress calls), like Parser.

/**
 * The v2 tokens (ADR-0015): `@( … )` formulas and `@fn( … )` calls, parsed
 * into a tree. Precedence, lowest first: `or`, `and`, `not`, comparisons
 * (`== != < <= > >=`, not chained), `+ -`, `* / %`, unary `-`, then methods
 * (`.fn(…)`) on a primary: a number, 'text', true/false, `( … )`, a value
 * (`@post.title`) or a call (`@round(…)` or `round(…)`).
 *
 * Nodes (key order matters: the TypeScript parser produces the same JSON):
 *   {type: number|text|bool, value}   {type: ref, name}
 *   {type: call, fn, args}            {type: binary, op, left, right}
 *   {type: unary, op: "-"|"not", arg}
 * A method `x.fn(a)` is the call `fn(x, a)`.
 *
 * Offsets are bytes here; Parser converts them to characters.
 */
final class Formula
{
    private const IDENT = '/\G[A-Za-z_][A-Za-z0-9_]*/';

    private const COMPARISONS = ['==', '!=', '<=', '>=', '<', '>'];

    private int $i = 0;

    private int $depth = 0;

    private readonly int $n;

    /**
     * @param array<string, array{params: list<string>, required: int, variadic?: bool}> $functions
     */
    private function __construct(private readonly string $s, private readonly array $functions)
    {
        $this->n = strlen($s);
    }

    /** Whether a v2 token starts at $at (an `@` that starts a token): `@(`, or `@fn(` for a known fn. */
    public static function startsAt(string $s, int $at, array $functions): bool
    {
        if (($s[$at + 1] ?? '') === '(') {
            return true;
        }

        return preg_match(self::IDENT, $s, $m, 0, $at + 1) === 1
            && ($s[$at + 1 + strlen($m[0])] ?? '') === '('
            && isset($functions[$m[0]]);
    }

    /**
     * The token at $at: its tree and where it ends, or an error and the end of
     * its extent (the balanced parentheses and methods it spans).
     *
     * @param array<string, array{params: list<string>, required: int, variadic?: bool}> $functions
     * @return array{expr: array<string, mixed>|null, end: int, error?: string, errorAt?: int}
     */
    public static function token(string $s, int $at, array $functions): array
    {
        $parser = new self($s, $functions);
        $parser->i = $at + 1;

        try {
            if ($s[$parser->i] === '(') {
                $parser->i++;
                $parser->enter();
                $node = $parser->expression();
                $parser->expect(')');
                $parser->depth--;
            } else {
                preg_match(self::IDENT, $s, $m, 0, $parser->i);
                $nameAt = $parser->i;
                $parser->i += strlen($m[0]) + 1;
                $node = $parser->call($m[0], $nameAt, []);
            }

            return ['expr' => $parser->methods($node), 'end' => $parser->i];
        } catch (FormulaError $e) {
            return ['expr' => null, 'end' => self::extent($s, $at), 'error' => $e->getMessage(), 'errorAt' => $e->at];
        }
    }

    /**
     * How far a malformed token reaches: `@`, a name, balanced parentheses
     * (quotes respected), then `.fn(…)` methods.
     */
    private static function extent(string $s, int $at): int
    {
        $n = strlen($s);
        $i = $at + 1;
        if (preg_match(self::IDENT, $s, $m, 0, $i) === 1) {
            $i += strlen($m[0]);
        }
        if (($s[$i] ?? '') !== '(') {
            return $i;
        }
        $i = self::balanced($s, $i);
        while ($i < $n && $s[$i] === '.' && preg_match('/\G[A-Za-z_][A-Za-z0-9_]*\(/', $s, $c, 0, $i + 1) === 1) {
            $i = self::balanced($s, $i + strlen($c[0]));
        }

        return $i;
    }

    /** The index after the `)` matching the `(` at $i, or the end of the text. */
    private static function balanced(string $s, int $i): int
    {
        $n     = strlen($s);
        $depth = 0;
        for (; $i < $n; $i++) {
            $char = $s[$i];
            if ($char === "'" || $char === '"') {
                for ($i++; $i < $n && $s[$i] !== $char; $i++) {
                    if ($s[$i] === '\\') {
                        $i++;
                    }
                }
                if ($i >= $n) {
                    return $n;
                }
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return $i + 1;
            }
        }

        return $n;
    }

    /** @return array<string, mixed> */
    private function expression(): array
    {
        $left = $this->conjunction();
        while ($this->keyword('or')) {
            $left = ['type' => 'binary', 'op' => 'or', 'left' => $left, 'right' => $this->conjunction()];
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function conjunction(): array
    {
        $left = $this->negation();
        while ($this->keyword('and')) {
            $left = ['type' => 'binary', 'op' => 'and', 'left' => $left, 'right' => $this->negation()];
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function negation(): array
    {
        if ($this->keyword('not')) {
            return ['type' => 'unary', 'op' => 'not', 'arg' => $this->negation()];
        }

        return $this->comparison();
    }

    /** @return array<string, mixed> */
    private function comparison(): array
    {
        $left = $this->sum();
        $this->skip();
        foreach (self::COMPARISONS as $op) {
            if (substr($this->s, $this->i, strlen($op)) === $op) {
                $this->i += strlen($op);
                return ['type' => 'binary', 'op' => $op, 'left' => $left, 'right' => $this->sum()];
            }
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function sum(): array
    {
        $left = $this->product();
        while (true) {
            $this->skip();
            $op = $this->s[$this->i] ?? '';
            if ($op !== '+' && $op !== '-') {
                return $left;
            }
            $this->i++;
            $left = ['type' => 'binary', 'op' => $op, 'left' => $left, 'right' => $this->product()];
        }
    }

    /** @return array<string, mixed> */
    private function product(): array
    {
        $left = $this->unary();
        while (true) {
            $this->skip();
            $op = $this->s[$this->i] ?? '';
            if ($op !== '*' && $op !== '/' && $op !== '%') {
                return $left;
            }
            $this->i++;
            $left = ['type' => 'binary', 'op' => $op, 'left' => $left, 'right' => $this->unary()];
        }
    }

    /** @return array<string, mixed> */
    private function unary(): array
    {
        $this->skip();
        if (($this->s[$this->i] ?? '') === '-') {
            $this->i++;
            return ['type' => 'unary', 'op' => '-', 'arg' => $this->unary()];
        }

        return $this->methods($this->primary());
    }

    /** @return array<string, mixed> */
    private function primary(): array
    {
        $this->skip();
        $char = $this->s[$this->i] ?? '';

        if ($char === '(') {
            $this->i++;
            $this->enter();
            $node = $this->expression();
            $this->expect(')');
            $this->depth--;
            return $node;
        }
        if ($char === "'" || $char === '"') {
            return ['type' => 'text', 'value' => $this->quoted()];
        }
        if (preg_match('/\G\d+(\.\d+)?/', $this->s, $num, 0, $this->i) === 1) {
            $this->i += strlen($num[0]);
            return ['type' => 'number', 'value' => str_contains($num[0], '.') ? (float) $num[0] : (int) $num[0]];
        }

        $at = $this->i;
        $sigil = $char === '@';
        if ($sigil) {
            $this->i++;
            if (($this->s[$this->i] ?? '') === '(') {
                return $this->primary();
            }
        }
        if (preg_match(self::IDENT, $this->s, $m, 0, $this->i) !== 1) {
            throw new FormulaError('bad_formula', $at);
        }
        $name   = $m[0];
        $nameAt = $this->i;
        $this->i += strlen($name);

        if (($this->s[$this->i] ?? '') === '(') {
            $this->i++;
            return $this->call($name, $nameAt, []);
        }
        if (!$sigil) {
            if ($name === 'true' || $name === 'false') {
                return ['type' => 'bool', 'value' => $name === 'true'];
            }
            throw new FormulaError('bad_formula', $at);
        }

        return $this->reference($name, $at);
    }

    /** @return array<string, mixed> */
    private function reference(string $name, int $at): array
    {
        // `@post.title` is a namespaced name; `@date.upper()` is a field named "date" with a call.
        if (
            in_array($name, Parser::NAMESPACES, true) && ($this->s[$this->i] ?? '') === '.'
            && preg_match(self::IDENT, $this->s, $m, 0, $this->i + 1) === 1
            && ($this->s[$this->i + 1 + strlen($m[0])] ?? '') !== '('
        ) {
            $name .= '.' . $m[0];
            $this->i += 1 + strlen($m[0]);
        }
        if ($name === 'site' || str_starts_with($name, 'site.') && !in_array(substr($name, 5), Parser::SITE_PROPERTIES, true)) {
            throw new FormulaError('unknown_name', $at);
        }

        return ['type' => 'ref', 'name' => $name];
    }

    /**
     * A call whose `(` was just consumed; $receiver is the method's value.
     *
     * @param list<array<string, mixed>> $receiver
     * @return array<string, mixed>
     */
    private function call(string $fn, int $nameAt, array $receiver): array
    {
        if (!isset($this->functions[$fn])) {
            throw new FormulaError('unknown_function', $nameAt);
        }
        $this->enter();
        $args = [...$receiver, ...$this->arguments()];
        $this->depth--;
        if (Functions::check($this->functions[$fn], $args) !== null) {
            throw new FormulaError('wrong_arguments', $nameAt);
        }

        return ['type' => 'call', 'fn' => $fn, 'args' => $args];
    }

    /**
     * `.fn(…)` methods after a value. A dot that isn't followed by `name(`
     * isn't a method: at the top level it's text (a sentence's period).
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function methods(array $node): array
    {
        while (($this->s[$this->i] ?? '') === '.' && preg_match('/\G[A-Za-z_][A-Za-z0-9_]*\(/', $this->s, $c, 0, $this->i + 1) === 1) {
            $nameAt = $this->i + 1;
            $this->i += 1 + strlen($c[0]);
            $node = $this->call(substr($c[0], 0, -1), $nameAt, [$node]);
        }

        return $node;
    }

    /**
     * Arguments after `(`, up to and including `)`.
     *
     * @return list<array<string, mixed>>
     */
    private function arguments(): array
    {
        $this->skip();
        if (($this->s[$this->i] ?? '') === ')') {
            $this->i++;
            return [];
        }

        $args = [];
        while (true) {
            $args[] = $this->expression();
            $this->skip();
            $char = $this->s[$this->i] ?? '';
            if ($char === ')') {
                $this->i++;
                return $args;
            }
            if ($char !== ',') {
                throw new FormulaError('bad_formula', $this->i);
            }
            $this->i++;
        }
    }

    private function quoted(): string
    {
        $at    = $this->i;
        $quote = $this->s[$this->i++];
        $value = '';
        while ($this->i < $this->n && $this->s[$this->i] !== $quote) {
            if ($this->s[$this->i] === '\\' && $this->i + 1 < $this->n) {
                $this->i++;
            }
            $value .= $this->s[$this->i++];
        }
        if ($this->i >= $this->n) {
            throw new FormulaError('bad_formula', $at);
        }
        $this->i++;

        return $value;
    }

    private function keyword(string $word): bool
    {
        $this->skip();
        if (preg_match('/\G' . $word . '(?![A-Za-z0-9_])/', $this->s, $m, 0, $this->i) !== 1) {
            return false;
        }
        $this->i += strlen($word);

        return true;
    }

    private function expect(string $char): void
    {
        $this->skip();
        if (($this->s[$this->i] ?? '') !== $char) {
            throw new FormulaError('bad_formula', $this->i);
        }
        $this->i++;
    }

    private function enter(): void
    {
        if (++$this->depth > Parser::MAX_DEPTH) {
            throw new FormulaError('too_deep', $this->i);
        }
    }

    private function skip(): void
    {
        while ($this->i < $this->n && in_array($this->s[$this->i], [' ', "\t", "\n", "\r"], true)) {
            $this->i++;
        }
    }
}

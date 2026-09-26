<?php

declare(strict_types=1);

namespace TAW\Core\Bindings\Expression;

// No ABSPATH guard: pure class (no WordPress calls), like Editing\Blocks.

/**
 * The expression grammar (ADR-0012), v1:
 *
 *   Published on @book_date.format('F j, Y') by @post.author · @option.phone.default('—')
 *
 * - Text, with tokens. A token starts with `@` at the start or after a
 *   non-word character (so name@example.com stays text), then a name:
 *   a field id (`book_date`), or a namespace and a name (`post.title`,
 *   `site.name`, `option.x`, `term.x`, `author.x`).
 * - Then any number of calls: `.name(args)`, where args are quoted strings
 *   ('…' or "…", backslash escapes) or numbers. A `.` not followed by
 *   `name(` is text: "Released in @book_year." ends a sentence.
 * - `@@` is a literal `@`.
 * - Offsets (`at`, `length`) count characters (code points).
 *
 * The result is a list of parts plus errors, as plain arrays (the same shape
 * the TypeScript parser produces; tests/fixtures/expressions.json holds the
 * cases both must pass):
 *
 *   ['text' => 'Published on ']
 *   ['name' => 'book_date', 'calls' => [['fn' => 'format', 'args' => ['F j, Y']]], 'at' => 13, 'length' => 27]
 *   errors: ['code' => 'unknown_function', 'at' => 20]
 *
 * A token with an error still appears in the parts (with 'error' set); the
 * evaluator renders it empty.
 */
final class Parser
{
    public const MAX_LENGTH = 500;

    public const MAX_TOKENS = 20;

    public const NAMESPACES = ['post', 'site', 'option', 'term', 'author', 'viewer', 'date'];

    /** Function name → its argument kinds. */
    public const FUNCTIONS = [
        'format'   => ['string'],
        'upper'    => [],
        'lower'    => [],
        'default'  => ['string'],
        'truncate' => ['int'],
    ];

    private const IDENT = '/\G[A-Za-z_][A-Za-z0-9_]*/';

    /**
     * @return array{parts: list<array<string, mixed>>, errors: list<array{code: string, at: int}>}
     */
    public static function parse(string $expression): array
    {
        if (mb_strlen($expression) > self::MAX_LENGTH) {
            return ['parts' => [], 'errors' => [['code' => 'too_long', 'at' => 0]]];
        }

        $parts  = [];
        $errors = [];
        $text   = '';
        $tokens = 0;
        $i      = 0;
        $n      = strlen($expression);

        while ($i < $n) {
            $char = $expression[$i];

            if ($char === '@' && ($expression[$i + 1] ?? '') === '@') {
                $text .= '@';
                $i += 2;
                continue;
            }

            if ($char === '@' && self::startsToken($expression, $i)) {
                $token = self::token($expression, $i);
                if ($text !== '') {
                    $parts[] = ['text' => $text];
                    $text    = '';
                }
                if (++$tokens > self::MAX_TOKENS) {
                    return ['parts' => [], 'errors' => [['code' => 'too_many_tokens', 'at' => $i]]];
                }
                if (isset($token['error'])) {
                    $errors[] = ['code' => $token['error'], 'at' => $token['errorAt']];
                    unset($token['errorAt']);
                }
                $parts[] = $token;
                $i += $token['length'];
                continue;
            }

            $text .= $char;
            $i++;
        }

        if ($text !== '') {
            $parts[] = ['text' => $text];
        }

        return self::inCharacters($expression, $parts, $errors);
    }

    /**
     * Offsets counted in characters (code points), not bytes, so they match
     * the TypeScript parser's for text like "·" or "—".
     *
     * @param list<array<string, mixed>> $parts
     * @param list<array{code: string, at: int}> $errors
     * @return array{parts: list<array<string, mixed>>, errors: list<array{code: string, at: int}>}
     */
    private static function inCharacters(string $s, array $parts, array $errors): array
    {
        $chars = static fn (int $bytes): int => mb_strlen(substr($s, 0, $bytes), 'UTF-8');

        foreach ($parts as $k => $part) {
            if (isset($part['at'], $part['length'])) {
                $start = (int) $part['at'];
                $parts[$k]['at'] = $chars($start);
                $parts[$k]['length'] = $chars($start + (int) $part['length']) - $parts[$k]['at'];
            }
        }
        foreach ($errors as $k => $error) {
            $errors[$k]['at'] = $chars($error['at']);
        }

        return ['parts' => $parts, 'errors' => $errors];
    }

    /** An `@` followed by a name, at the start or after a non-word character. */
    private static function startsToken(string $s, int $i): bool
    {
        $before = $i > 0 ? $s[$i - 1] : ' ';

        return preg_match('/[A-Za-z0-9_]/', $before) !== 1 && preg_match(self::IDENT, $s, $m, 0, $i + 1) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private static function token(string $s, int $at): array
    {
        $i = $at + 1;
        preg_match(self::IDENT, $s, $m, 0, $i);
        $name = $m[0];
        $i += strlen($name);

        // `@post.title` is a namespaced name; `@date.upper()` is a field named "date" with a call.
        if (
            in_array($name, self::NAMESPACES, true) && ($s[$i] ?? '') === '.' && preg_match(self::IDENT, $s, $m2, 0, $i + 1) === 1
            && ($s[$i + 1 + strlen($m2[0])] ?? '') !== '('
        ) {
            $name .= '.' . $m2[0];
            $i += 1 + strlen($m2[0]);
        }

        $token = ['name' => $name, 'calls' => [], 'at' => $at];

        // Calls: `.fn(` … `)`. A dot not followed by `fn(` ends the token.
        while (($s[$i] ?? '') === '.' && preg_match('/\G[A-Za-z_][A-Za-z0-9_]*\(/', $s, $c, 0, $i + 1) === 1) {
            $fn    = substr($c[0], 0, -1);
            $fnAt  = $i + 1;
            $i    += 1 + strlen($c[0]);
            $args  = self::arguments($s, $i);

            if ($args === null) {
                // Unclosed or malformed: the token ends here, the rest is text.
                $token['error'] ??= 'bad_arguments';
                $token['errorAt'] ??= $fnAt;

                return self::shaped($token, $i - $at);
            }
            [$values, $i] = $args;

            $token['calls'][] = ['fn' => $fn, 'args' => $values];
            $error = self::checkCall($fn, $values);
            if ($error !== null && !isset($token['error'])) {
                $token['error']   = $error;
                $token['errorAt'] = $fnAt;
            }
        }

        if ($name === 'site' || str_starts_with($name, 'site.') && !in_array(substr($name, 5), ['name', 'tagline', 'url', 'year'], true)) {
            $token['error'] ??= 'unknown_name';
            $token['errorAt'] ??= $at;
        }

        return self::shaped($token, $i - $at);
    }

    /**
     * One key order for every token (name, calls, at, length, error), so both
     * parsers produce the same JSON.
     *
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    private static function shaped(array $token, int $length): array
    {
        $out = ['name' => $token['name'], 'calls' => $token['calls'], 'at' => $token['at'], 'length' => $length];
        if (isset($token['error'])) {
            $out['error']   = $token['error'];
            $out['errorAt'] = $token['errorAt'];
        }

        return $out;
    }

    /**
     * Arguments after `(`, up to and including `)`.
     *
     * @return array{0: list<string|int|float>, 1: int}|null The values and the index after `)`, or null.
     */
    private static function arguments(string $s, int $i): ?array
    {
        $values = [];
        $n = strlen($s);

        $skip = static function () use ($s, &$i, $n): void {
            while ($i < $n && ($s[$i] === ' ' || $s[$i] === "\t")) {
                $i++;
            }
        };

        $skip();
        if (($s[$i] ?? '') === ')') {
            return [[], $i + 1];
        }

        while ($i < $n) {
            $skip();
            $char = $s[$i] ?? '';

            if ($char === "'" || $char === '"') {
                $value = '';
                $i++;
                while ($i < $n && $s[$i] !== $char) {
                    if ($s[$i] === '\\' && $i + 1 < $n) {
                        $i++;
                    }
                    $value .= $s[$i];
                    $i++;
                }
                if ($i >= $n) {
                    return null;
                }
                $i++;
                $values[] = $value;
            } elseif (preg_match('/\G-?\d+(\.\d+)?/', $s, $num, 0, $i) === 1) {
                $values[] = str_contains($num[0], '.') ? (float) $num[0] : (int) $num[0];
                $i += strlen($num[0]);
            } else {
                return null;
            }

            $skip();
            $char = $s[$i] ?? '';
            if ($char === ')') {
                return [$values, $i + 1];
            }
            if ($char !== ',') {
                return null;
            }
            $i++;
        }

        return null;
    }

    /**
     * @param list<string|int|float> $args
     */
    private static function checkCall(string $fn, array $args): ?string
    {
        if (!isset(self::FUNCTIONS[$fn])) {
            return 'unknown_function';
        }

        $kinds = self::FUNCTIONS[$fn];
        if (count($args) !== count($kinds)) {
            return 'wrong_arguments';
        }
        foreach ($kinds as $k => $kind) {
            $ok = $kind === 'string' ? is_string($args[$k]) : (is_int($args[$k]) && $args[$k] > 0);
            if (!$ok) {
                return 'wrong_arguments';
            }
        }

        return null;
    }
}

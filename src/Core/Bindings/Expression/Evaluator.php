<?php

declare(strict_types=1);

namespace TAW\Core\Bindings\Expression;

use TAW\Core\Bindings\BindingContext;
use TAW\Core\Bindings\InlineTags;
use TAW\Core\Bindings\TagResolver;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Evaluates an expression (ADR-0012, ADR-0015) to one line of plain,
 * unescaped text: each value through the same resolvers as tags and bindings
 * (so privacy, `bindings: false` and the post context are the same), then its
 * functions and operators, on typed values (Value).
 *
 * Nothing is ever executed but the listed functions. A token with an error,
 * or an unknown name, renders empty (`default()` aside); an evaluation error
 * (division by zero, text in arithmetic) empties its token and is reported.
 */
final class Evaluator
{
    private function __construct(private readonly BindingContext $context, private readonly ?string $format)
    {
    }

    /**
     * @return array{value: string, errors: list<array{code: string, at: int}>}
     */
    public static function evaluate(string $expression, BindingContext $context): array
    {
        $parsed = Parser::parse($expression);
        $errors = $parsed['errors'];
        $run    = new self($context, null);
        $out    = '';

        foreach ($parsed['parts'] as $part) {
            if (isset($part['text'])) {
                $out .= (string) $part['text'];
                continue;
            }
            try {
                $out .= Value::text($run->part($part));
            } catch (EvaluationError $e) {
                $errors[] = ['code' => $e->getMessage(), 'at' => (int) $part['at']];
            }
        }

        return ['value' => trim($out), 'errors' => $errors];
    }

    /**
     * One token's value (`@post.date.format('Y')`, `@(@price * 2)`), as plain
     * text: '' when the token is invalid, empty or fails. $format is the date
     * format to use when a value has no `format()` of its own (conditions
     * compare dates with it).
     */
    public static function tokenValue(string $token, BindingContext $context, ?string $format = null): string
    {
        $parsed = Parser::parse($token);
        $parts  = $parsed['parts'];
        if (count($parts) !== 1 || isset($parts[0]['text']) || isset($parts[0]['error'])) {
            return '';
        }

        try {
            return Value::text((new self($context, $format))->part($parts[0]));
        } catch (EvaluationError) {
            return '';
        }
    }

    /**
     * The value args a name reads (the tag/field args InlineTags::value()
     * takes), or null for a name that can't exist.
     *
     * @return array<string, string>|null
     */
    public static function argsFor(string $name): ?array
    {
        [$space, $rest] = str_contains($name, '.') ? explode('.', $name, 2) : ['', $name];

        return match ($space) {
            ''       => ['field' => $rest, 'from' => 'post'],
            'post'   => TagResolver::knows("post.{$rest}") ? ['tag' => "post.{$rest}"] : ['field' => $rest, 'from' => 'post'],
            'site'   => TagResolver::knows("site.{$rest}") ? ['tag' => "site.{$rest}"] : null,
            'option' => ['field' => $rest, 'from' => 'option'],
            'term'   => ['field' => $rest, 'from' => 'term'],
            'author' => ['field' => $rest, 'from' => 'user'],
            'viewer' => TagResolver::knows("viewer.{$rest}") ? ['tag' => "viewer.{$rest}"] : null,
            'date'   => TagResolver::knows("date.{$rest}") ? ['tag' => "date.{$rest}"] : null,
            'row'    => ['row' => $rest],
            'loop'   => ['loop' => $rest],
            default  => null,
        };
    }

    /**
     * A token part: v1 (`name` + literal calls) or v2 (`expr`).
     *
     * @param array<string, mixed> $part
     * @throws EvaluationError
     */
    private function part(array $part): mixed
    {
        if (array_key_exists('expr', $part)) {
            return is_array($part['expr']) ? $this->node($part['expr']) : '';
        }
        $calls = is_array($part['calls'] ?? null) ? $part['calls'] : [];
        // A token with an error reads nothing, but its calls still run (so `default()` applies).
        $broken = isset($part['error']);
        $value  = $broken ? '' : $this->read((string) ($part['name'] ?? ''), $this->formatIn($calls));
        foreach ($calls as $call) {
            if ($call['fn'] === 'format' || !isset(Functions::SIGNATURES[$call['fn']])) {
                continue;
            }
            try {
                $value = Functions::apply((string) $call['fn'], [$value, ...$call['args']]);
            } catch (EvaluationError $e) {
                if (!$broken) {
                    throw $e;
                }
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $node
     * @throws EvaluationError
     */
    private function node(array $node): mixed
    {
        return match ($node['type']) {
            'number', 'text', 'bool' => $node['value'],
            'ref'    => $this->read((string) $node['name'], $this->format),
            'unary'  => $node['op'] === 'not' ? !Value::truthy($this->node($node['arg'])) : $this->negate($this->node($node['arg'])),
            'binary' => $this->binary((string) $node['op'], $node['left'], $node['right']),
            'call'   => $this->call((string) $node['fn'], $node['args']),
            default  => '',
        };
    }

    /**
     * @param list<array<string, mixed>> $args
     * @throws EvaluationError
     */
    private function call(string $fn, array $args): mixed
    {
        // Lazy: only the branch that's used is evaluated.
        if ($fn === 'if') {
            return Value::truthy($this->node($args[0])) ? $this->node($args[1]) : (isset($args[2]) ? $this->node($args[2]) : '');
        }
        if ($fn === 'coalesce') {
            foreach ($args as $arg) {
                $value = $this->node($arg);
                if (!Value::isEmpty($value)) {
                    return $value;
                }
            }
            return '';
        }
        // A format on a value reads it in that format (dates).
        if ($fn === 'format' && ($args[0]['type'] ?? '') === 'ref') {
            return $this->read((string) $args[0]['name'], Value::text($this->node($args[1])));
        }

        return Functions::apply($fn, array_map($this->node(...), $args));
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @throws EvaluationError
     */
    private function binary(string $op, array $left, array $right): mixed
    {
        if ($op === 'and') {
            return Value::truthy($this->node($left)) && Value::truthy($this->node($right));
        }
        if ($op === 'or') {
            return Value::truthy($this->node($left)) || Value::truthy($this->node($right));
        }

        $a = $this->node($left);
        $b = $this->node($right);

        if (in_array($op, ['==', '!=', '<', '<=', '>', '>='], true)) {
            $cmp = Value::isNumeric($a) && Value::isNumeric($b)
                ? Value::toNumber($a) <=> Value::toNumber($b)
                : strcmp(Value::text($a), Value::text($b)) <=> 0;

            return match ($op) {
                '=='    => $cmp === 0,
                '!='    => $cmp !== 0,
                '<'     => $cmp < 0,
                '<='    => $cmp <= 0,
                '>'     => $cmp > 0,
                default => $cmp >= 0,
            };
        }

        // Arithmetic: an empty operand empties the result (a missing price shows nothing).
        if (Value::isEmpty($a) || Value::isEmpty($b)) {
            return '';
        }
        $x = Value::toNumber($a);
        $y = Value::toNumber($b);
        if (($op === '/' || $op === '%') && $y == 0) {
            throw new EvaluationError('division_by_zero');
        }

        return match ($op) {
            '+'     => $x + $y,
            '-'     => $x - $y,
            '*'     => $x * $y,
            '/'     => $x / $y,
            default => is_int($x) && is_int($y) ? $x % $y : fmod((float) $x, (float) $y),
        };
    }

    /** @throws EvaluationError */
    private function negate(mixed $value): mixed
    {
        return Value::isEmpty($value) ? '' : -Value::toNumber($value);
    }

    /** A value by name, as text ('' for a name that can't exist). */
    private function read(string $name, ?string $format): string
    {
        $args = self::argsFor($name);
        if ($args === null) {
            return '';
        }
        if ($format !== null) {
            $args['format'] = $format;
        }

        return InlineTags::value($args, $this->context) ?? '';
    }

    /**
     * The format a v1 token reads with: its own `format()`, else the caller's.
     *
     * @param list<array<string, mixed>> $calls
     */
    private function formatIn(array $calls): ?string
    {
        $format = $this->format;
        foreach ($calls as $call) {
            if ($call['fn'] === 'format') {
                $format = (string) $call['args'][0];
            }
        }

        return $format;
    }
}

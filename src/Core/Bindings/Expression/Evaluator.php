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
 * Evaluates an expression (ADR-0012) to one line of plain, unescaped text:
 * each token through the same resolvers as tags and bindings (so privacy,
 * `bindings: false` and the post context are the same), then its functions.
 *
 * Nothing is ever executed: the parser only recognizes names and the five
 * functions. A token with an error, or an unknown name, renders empty
 * (`default()` aside).
 */
final class Evaluator
{
    /**
     * @return array{value: string, errors: list<array{code: string, at: int}>}
     */
    public static function evaluate(string $expression, BindingContext $context): array
    {
        $parsed = Parser::parse($expression);
        $out = '';

        foreach ($parsed['parts'] as $part) {
            $out .= isset($part['text']) ? (string) $part['text'] : self::token($part, $context);
        }

        return ['value' => trim($out), 'errors' => $parsed['errors']];
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
            default  => null,
        };
    }

    /**
     * @param array<string, mixed> $token
     */
    private static function token(array $token, BindingContext $context): string
    {
        $calls = is_array($token['calls'] ?? null) ? $token['calls'] : [];
        $args  = isset($token['error']) ? null : self::argsFor((string) ($token['name'] ?? ''));

        $value = '';
        if ($args !== null) {
            foreach ($calls as $call) {
                if ($call['fn'] === 'format') {
                    $args['format'] = (string) $call['args'][0];
                }
            }
            $value = InlineTags::value($args, $context) ?? '';
        }

        foreach ($calls as $call) {
            $value = match ($call['fn']) {
                'upper'    => mb_strtoupper($value, 'UTF-8'),
                'lower'    => mb_strtolower($value, 'UTF-8'),
                'default'  => $value === '' ? (string) $call['args'][0] : $value,
                'truncate' => self::truncate($value, (int) $call['args'][0]),
                default    => $value,
            };
        }

        return $value;
    }

    private static function truncate(string $value, int $length): string
    {
        return mb_strlen($value, 'UTF-8') <= $length ? $value : rtrim(mb_substr($value, 0, $length, 'UTF-8')) . '…';
    }
}

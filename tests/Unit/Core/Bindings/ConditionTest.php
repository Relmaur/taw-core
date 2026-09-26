<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Bindings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TAW\Core\Bindings\Condition\Condition;
use TAW\Core\Bindings\Condition\Evaluator;

/**
 * Conditions (ADR-0013) against the shared fixture: shapes (which the
 * editor's validator must agree on) and evaluation with fixed values.
 */
final class ConditionTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../../fixtures/conditions.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return iterable<string, array{mixed, list<array{code: string, path: string}>}>
     */
    public static function shapes(): iterable
    {
        foreach (self::fixture()['shape'] as $case) {
            yield $case['name'] => [$case['condition'], $case['errors']];
        }
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function evaluations(): iterable
    {
        foreach (self::fixture()['evaluate'] as $case) {
            yield $case['name'] => [$case['condition'] ?? ['rules' => [$case['rule']]], $case['shown']];
        }
    }

    /**
     * @param list<array{code: string, path: string}> $errors
     */
    #[DataProvider('shapes')]
    public function test_shapes(mixed $condition, array $errors): void
    {
        $result = Condition::normalize($condition);

        $this->assertSame($errors, $result['errors']);
        $this->assertSame($errors === [], $result['condition'] !== null);
    }

    #[DataProvider('evaluations')]
    public function test_evaluation(mixed $condition, bool $shown): void
    {
        $this->assertSame($shown, $this->evaluator()->check($condition)['shown']);
    }

    public function test_date_operators_read_values_as_dates(): void
    {
        $asked = [];
        $reader = static function (string $token, bool $asDate) use (&$asked): string {
            $asked[] = [$token, $asDate];
            return '2026-09-10 09:00:00';
        };
        $evaluator = new Evaluator($reader, new \DateTimeZone('UTC'), new \DateTimeImmutable('2026-09-26 00:00:00'));

        // "any", so both rules run (the first one is false: the same date).
        $evaluator->check(['match' => 'any', 'rules' => [
            ['value' => '@post.date', 'op' => 'after', 'to' => '@post.modified'],
            ['value' => '@post.title', 'op' => 'equals', 'to' => 'x'],
        ]]);

        $this->assertSame([['@post.date', true], ['@post.modified', true], ['@post.title', false]], $asked);
    }

    public function test_any_stops_at_the_first_rule_that_holds(): void
    {
        $asked = [];
        $reader = static function (string $token) use (&$asked): string {
            $asked[] = $token;
            return 'x';
        };
        $evaluator = new Evaluator($reader, new \DateTimeZone('UTC'), new \DateTimeImmutable());

        $evaluator->check(['match' => 'any', 'rules' => [['value' => '@a', 'op' => 'not_empty'], ['value' => '@b', 'op' => 'not_empty']]]);

        $this->assertSame(['@a'], $asked);
    }

    public function test_an_invalid_condition_reports_its_errors(): void
    {
        $result = $this->evaluator()->check(['rules' => [['value' => '@x', 'op' => 'nope']]]);

        $this->assertSame(['shown' => false, 'errors' => [['code' => 'unknown_op', 'path' => 'rules.0.op']]], $result);
    }

    public function test_every_operator_has_an_arity_and_a_fixture_case(): void
    {
        $covered = array_map(static fn (array $case): string => $case['rule']['op'] ?? '', self::fixture()['evaluate']);

        foreach (array_keys(Condition::OPERATORS) as $op) {
            $this->assertContains($op, $covered, "No evaluate case for {$op}");
        }
    }

    private function evaluator(): Evaluator
    {
        $fixture = self::fixture();
        $values = $fixture['values'];
        $timezone = new \DateTimeZone($fixture['timezone']);

        return new Evaluator(
            static fn (string $token): string => $values[$token] ?? '',
            $timezone,
            new \DateTimeImmutable($fixture['now'], $timezone),
        );
    }
}

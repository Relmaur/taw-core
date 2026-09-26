<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Bindings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TAW\Core\Bindings\Expression\Parser;

/**
 * The expression grammar (ADR-0012) against the shared fixture file, which
 * the TypeScript parser must pass too.
 */
final class ExpressionParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<array<string, mixed>>, list<array<string, mixed>>}>
     */
    public static function cases(): iterable
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../../fixtures/expressions.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            yield $case['name'] => [$case['expr'], $case['parts'], $case['errors']];
        }
    }

    /**
     * @param list<array<string, mixed>> $parts
     * @param list<array<string, mixed>> $errors
     */
    #[DataProvider('cases')]
    public function test_the_shared_fixture(string $expression, array $parts, array $errors): void
    {
        $this->assertSame(['parts' => $parts, 'errors' => $errors], Parser::parse($expression));
    }

    public function test_input_over_the_limit_is_refused(): void
    {
        $this->assertSame(['parts' => [], 'errors' => [['code' => 'too_long', 'at' => 0]]], Parser::parse(str_repeat('a', Parser::MAX_LENGTH + 1)));
        $this->assertCount(1, Parser::parse(str_repeat('é', Parser::MAX_LENGTH))['parts'], '500 characters, not bytes');
    }
}

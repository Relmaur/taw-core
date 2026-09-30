<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Bindings;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions as WP;
use PHPUnit\Framework\Attributes\DataProvider;
use TAW\Core\Bindings\Expression\EvaluationError;
use TAW\Core\Bindings\Expression\Functions;
use TAW\Core\Bindings\Expression\Parser;
use TAW\Core\Bindings\Expression\Value;
use TAW\Tests\TestCase;

/**
 * The expression function library (ADR-0015): each function on evaluated
 * values, and the site's own functions from `taw_expression_functions`.
 */
final class ExpressionFunctionsTest extends TestCase
{
    /** "Now" is 2026-09-30 12:00 UTC. */
    private const NOW = 1790769600;

    protected function setUp(): void
    {
        parent::setUp();
        Functions::reset();

        WP\when('wp_timezone')->alias(static fn (): \DateTimeZone => new \DateTimeZone('UTC'));
        WP\when('current_datetime')->alias(static fn (): \DateTimeImmutable => new \DateTimeImmutable('@' . self::NOW));
        WP\when('wp_date')->alias(static fn (string $format, ?int $ts = null): string => gmdate($format, $ts ?? self::NOW));
        WP\when('number_format_i18n')->alias(static fn (float $n, int $decimals = 0): string => number_format($n, $decimals));
        WP\when('human_time_diff')->alias(static fn (int $from, int $to): string => sprintf('%d days', (int) round(abs($to - $from) / 86400)));
        WP\when('sanitize_title')->alias(static fn (string $t): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($t)), '-'));
        WP\when('wp_strip_all_tags')->alias(static fn (string $t): string => strip_tags($t));
        WP\when('__')->returnArg(1);
        WP\when('esc_html')->returnArg(1);
    }

    protected function tearDown(): void
    {
        Functions::reset();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, list<mixed>, mixed}>
     */
    public static function cases(): iterable
    {
        $awards = [['name' => 'Hugo', 'year' => 1966], ['name' => 'Nebula', 'year' => 1965], ['name' => 'Locus', 'year' => '']];

        // Text.
        yield 'capitalize' => ['capitalize', ['éclair au chocolat'], 'Éclair au chocolat'];
        yield 'words' => ['words', ['one two  three four', 2], 'one two…'];
        yield 'words, short enough' => ['words', ['one two', 5], 'one two'];
        yield 'word_count' => ['word_count', ["one two\nthree"], 3];
        yield 'replace' => ['replace', ['Dune (1965)', '(1965)', ''], 'Dune '];
        yield 'strip' => ['strip', ['<p>Spice &amp; <b>sand</b></p>'], 'Spice &amp; sand'];
        yield 'slug' => ['slug', ['Dune Messiah!'], 'dune-messiah'];
        yield 'urlencode' => ['urlencode', ['a b&c'], 'a%20b%26c'];
        yield 'trim' => ['trim', ['  x  '], 'x'];
        yield 'plural, one' => ['plural', [1, 'award', 'awards'], '1 award'];
        yield 'plural, many' => ['plural', ['3', 'award', 'awards'], '3 awards'];
        yield 'plural, none' => ['plural', [0, 'award', 'awards'], '0 awards'];
        yield 'concat' => ['concat', ['a', 1, true, ''], 'a11'];
        // Numbers.
        yield 'round' => ['round', ['2.345', 2], 2.35];
        yield 'round, no digits' => ['round', [2.5], 3.0];
        yield 'floor, ceil, abs' => ['abs', [-3], 3];
        yield 'min over values and lists' => ['min', [5, ['3', 9], ''], 3];
        yield 'max' => ['max', [[1965, 1966, '']], 1966];
        yield 'number' => ['number', [1234.5], '1,234.50'];
        yield 'number, whole' => ['number', ['1234'], '1,234'];
        yield 'number, digits' => ['number', [1234.567, 1], '1,234.6'];
        yield 'currency' => ['currency', [1234.5, 'mxn'], '$1,234.50'];
        yield 'currency, no decimals' => ['currency', [1234.5, 'JPY'], '¥1,235'];
        yield 'currency, unknown code' => ['currency', [-5, 'XYZ'], '-5.00 XYZ'];
        yield 'percent' => ['percent', [0.256, 1], '25.6%'];
        // Dates.
        yield 'format a date value' => ['format', ['2026-09-27 08:00:00', 'j M Y'], '27 Sep 2026'];
        yield 'ago' => ['ago', ['2026-09-27 12:00:00'], '3 days ago'];
        yield 'ago, in the future' => ['ago', ['2026-10-07'], 'in 7 days'];
        yield 'until' => ['until', ['2026-10-07 12:00:00'], '7 days'];
        yield 'until, past' => ['until', ['2026-09-01'], ''];
        yield 'days_between' => ['days_between', ['2026-09-01', '2026-09-30'], 29];
        yield 'add_days' => ['add_days', ['2026-09-30', 3], '2026-10-03'];
        yield 'a year is a date' => ['year', [1965], 1965];
        yield 'month' => ['month', ['2026-09-30'], 'September'];
        yield 'day' => ['day', ['2026-09-07'], 7];
        yield 'weekday' => ['weekday', ['2026-09-30'], 'Wednesday'];
        yield 'an empty date' => ['ago', [''], ''];
        // Lists.
        yield 'count rows' => ['count', [$awards], 3];
        yield 'count text items' => ['count', ['a, b, , c'], 3];
        yield 'column' => ['column', [$awards, 'name'], ['Hugo', 'Nebula', 'Locus']];
        yield 'join' => ['join', [['Hugo', 'Nebula'], ' & '], 'Hugo & Nebula'];
        yield 'join, default separator' => ['join', [['Hugo', 'Nebula']], 'Hugo, Nebula'];
        yield 'first and last' => ['last', [['a', 'b', 'c']], 'c'];
        yield 'sort, numbers' => ['sort', [[10, '9', 100]], ['9', 10, 100]];
        yield 'sort, text' => ['sort', [['item10', 'Item2', 'item1']], ['item1', 'Item2', 'item10']];
        yield 'reverse' => ['reverse', [['a', 'b']], ['b', 'a']];
        yield 'contains' => ['contains', [['Fiction', 'Classic'], ' fiction '], true];
        yield 'sum, empty items skipped' => ['sum', [[1965, 1966, '']], 3931];
        yield 'avg' => ['avg', [['1', '2']], 1.5];
        yield 'avg of nothing' => ['avg', [[]], ''];
    }

    /**
     * @param list<mixed> $args
     */
    #[DataProvider('cases')]
    public function test_functions(string $fn, array $args, mixed $expected): void
    {
        $this->assertSame($expected, Functions::apply($fn, $args));
    }

    public function test_the_built_ins_match_the_shared_fixture(): void
    {
        // The TypeScript parser checks the same file (expression.test.ts).
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../../fixtures/expression-functions.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($fixture['signatures'], Functions::SIGNATURES);
    }

    public function test_values_read_as_text(): void
    {
        $this->assertSame('Hugo, Nebula', Value::text(['Hugo', '', 'Nebula']));
        $this->assertSame('3', Value::text(3.0));
        $this->assertSame('116.58', Value::text(100.5 * 1.16));
    }

    /**
     * @return iterable<string, array{string, list<mixed>, string}>
     */
    public static function failures(): iterable
    {
        yield 'text in arithmetic' => ['round', ['abc'], 'not_a_number'];
        yield 'a date that isn\'t one' => ['ago', ['not a date'], 'not_a_date'];
        yield 'a negative word count' => ['words', ['a b', -1], 'wrong_arguments'];
        yield 'sum of text' => ['sum', [['a']], 'not_a_number'];
    }

    /**
     * @param list<mixed> $args
     */
    #[DataProvider('failures')]
    public function test_failures(string $fn, array $args, string $code): void
    {
        $this->expectException(EvaluationError::class);
        $this->expectExceptionMessage($code);
        Functions::apply($fn, $args);
    }

    public function test_every_built_in_function_runs(): void
    {
        foreach (array_keys(Functions::SIGNATURES) as $fn) {
            if (in_array($fn, ['terms'], true)) {
                continue; // needs the post: the evaluator runs it
            }
            try {
                Functions::apply($fn, ['1', '2', '3']);
            } catch (EvaluationError $e) {
                $this->assertNotSame('unknown_function', $e->getMessage(), $fn);
            }
        }
        $this->addToAssertionCount(1);
    }

    public function test_a_site_function_from_the_filter(): void
    {
        Filters\expectApplied('taw_expression_functions')->andReturn([
            'with_tax' => ['args' => ['number'], 'callback' => static fn (float $price): float => $price * 1.16, 'label' => 'Price with tax', 'description' => 'Adds 16% VAT.'],
            'shout'    => ['args' => ['text', 'int'], 'required' => 1, 'callback' => static fn (string $t, int $n = 1): string => $t . str_repeat('!', max(1, $n))],
            'broken'   => ['args' => [], 'callback' => static function (): string {
                throw new \RuntimeException('nope');
            }],
            'upper'    => ['callback' => 'strtolower'],
            'bad name!' => ['callback' => 'strtolower'],
            'no_call'  => ['args' => ['number']],
            'bad_kind' => ['args' => ['money'], 'callback' => 'strtolower'],
        ]);
        WP\expect('_doing_it_wrong')->times(4);
        WP\when('do_action')->justReturn(null);

        $this->assertSame(
            ['with_tax' => ['params' => ['any'], 'required' => 1, 'label' => 'Price with tax', 'description' => 'Adds 16% VAT.'],
             'shout'    => ['params' => ['any', 'int'], 'required' => 1, 'label' => 'shout', 'description' => ''],
             'broken'   => ['params' => ['any'], 'required' => 0, 'label' => 'broken', 'description' => '']],
            Functions::forEditor()
        );
        $this->assertEqualsWithDelta(116.0, Functions::apply('with_tax', ['100']), 0.0001);
        $this->assertSame('hey!!', Functions::apply('shout', ['hey', 2]));
        $this->assertSame('', Functions::apply('broken', []), 'a failing callback gives an empty value');
        $this->assertSame('ABC', Functions::apply('upper', ['abc']), 'a built-in can\'t be replaced');

        // The parser knows the site's functions.
        $this->assertSame('call', Parser::parse('@with_tax(@price)')['parts'][0]['expr']['type']);
        $this->assertSame('wrong_arguments', Parser::parse("@shout('a', 0)")['parts'][0]['error']);
    }
}

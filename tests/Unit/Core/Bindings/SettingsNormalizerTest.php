<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Bindings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TAW\Core\Bindings\Settings\Normalizer;

/**
 * The shared spec tests/fixtures/block-settings.json (ADR-0016), which the
 * editor's settings.ts passes too.
 */
final class SettingsNormalizerTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/block-settings.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function classCases(): iterable
    {
        foreach (self::fixture()['classes'] as $i => $case) {
            yield "#{$i} {$case['in']}" => [$case['in'], $case['out']];
        }
    }

    /** @param list<string> $out */
    #[DataProvider('classCases')]
    public function test_classes(string $in, array $out): void
    {
        $this->assertSame($out, Normalizer::classes($in));
    }

    /** @return iterable<string, array{string, bool, string|null, string|null}> */
    public static function colorCases(): iterable
    {
        foreach (self::fixture()['colors'] as $i => $case) {
            yield "#{$i} {$case['in']}" => [$case['in'], $case['custom'] ?? true, $case['out'] ?? null, $case['dropped'] ?? null];
        }
    }

    #[DataProvider('colorCases')]
    public function test_colors(string $in, bool $custom, ?string $out, ?string $dropped): void
    {
        $this->assertSame(['value' => $out, 'dropped' => $dropped], Normalizer::color($in, self::fixture()['palette'], $custom));
    }

    public function test_attribute_names(): void
    {
        foreach (self::fixture()['attributeNames'] as $case) {
            $this->assertSame($case['ok'], Normalizer::isAttribute($case['in']), $case['in']);
        }
    }

    public function test_attribute_values(): void
    {
        foreach (self::fixture()['attributeValues'] as $case) {
            $this->assertSame(['value' => $case['out'] ?? null, 'dropped' => $case['dropped'] ?? null], Normalizer::attribute($case['name'], $case['in']), "{$case['name']}: {$case['in']}");
        }
    }

    public function test_settings_shape(): void
    {
        foreach (self::fixture()['settings'] as $i => $case) {
            $this->assertSame($case['out'], Normalizer::settings($case['in']), "case #{$i}");
        }
    }
}

<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use PHPUnit\Framework\Attributes\DataProvider;
use TAW\Tests\TestCase;

/**
 * Regression for Bug C (v1.25.0): the `content:*` CLI commands autoload
 * `TAW\Core\Content\*` classes *before* `require wp-load.php`. Those files
 * used to carry `if (!defined('ABSPATH')) exit;`, so the first reference to
 * e.g. `Importer::POLICIES` in `ContentImportCommand::execute()` silently
 * killed the process (exit 0, no output) — every `content:import` run
 * printed nothing.
 *
 * This runs a subprocess with **no** `ABSPATH` defined (unlike the PHPUnit
 * bootstrap, which defines it) and asserts the classes load and are usable.
 */
final class PreBootAutoloadTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function classExpressions(): array
    {
        return [
            ['\\TAW\\Core\\Content\\Importer::POLICIES'],
            ['\\TAW\\Core\\Content\\FieldCodec::STRUCTURED_TYPES'],
            ['\\TAW\\Core\\Content\\FieldKeys::DEFAULT_PREFIX'],
            ['\\TAW\\Core\\Content\\Exporter::SCHEMA_VERSION'],
            ['\\TAW\\Core\\Content\\ChangeSet::between([], [])'],
            ['\\TAW\\Core\\Content\\RegistryFingerprint::drift([], [])'],
            ['(new \\ReflectionClass(\\TAW\\Core\\Content\\MediaResolver::class))->getName()'],
        ];
    }

    #[DataProvider('classExpressions')]
    public function test_content_class_loads_without_wordpress(string $expr): void
    {
        $autoload = \dirname(__DIR__, 4) . '/vendor/autoload.php';
        $code = sprintf(
            'require %s; $v = %s; echo "LOADED\n";',
            var_export($autoload, true),
            $expr
        );

        $output = [];
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);

        $joined = implode("\n", $output);

        $this->assertSame(0, $exit, "Subprocess exited non-zero:\n{$joined}");
        $this->assertStringContainsString(
            'LOADED',
            $joined,
            "Referencing `{$expr}` without ABSPATH produced no output — the class file still has a fatal ABSPATH guard.\n{$joined}"
        );
    }
}

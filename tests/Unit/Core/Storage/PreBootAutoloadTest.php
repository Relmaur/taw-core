<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use TAW\Tests\TestCase;

/**
 * Regression: `TAW\CLI\CorpusInstallCommand::execute()` calls
 * `ProtectedSqlite::looksLikeSqliteFile()` (directly) and
 * `Corpus\Storage::dir()`/`ensureProtectedDir()`/`dbPath()` (via
 * `ProtectedSqlite`) *before* `require $wpLoad` — both used to carry
 * `if (!defined('ABSPATH')) exit;`, so the first reference to either class
 * silently killed the whole CLI process (exit 0, no output) the moment
 * `bin/taw corpus:install` ran, before it ever got to validate the file or
 * print an error. Same failure class the PHPUnit bootstrap's global
 * `define('ABSPATH', ...)` can never catch on its own — see
 * `\TAW\Tests\Unit\Core\Content\PreBootAutoloadTest`, the original of this
 * pattern for `Content\*`.
 *
 * This runs a subprocess with **no** `ABSPATH` defined and asserts the
 * classes load and are usable.
 */
final class PreBootAutoloadTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function classExpressions(): array
    {
        return [
            ['\\TAW\\Core\\Storage\\ProtectedSqlite::SQLITE_MAGIC'],
            ['\\TAW\\Core\\Storage\\ProtectedSqlite::looksLikeSqliteFile(\'/nonexistent/path\') === false'],
            ['\\TAW\\Core\\Corpus\\Storage::class'],
            ['(new \\ReflectionMethod(\\TAW\\Core\\Corpus\\Storage::class, \'dbPath\'))->getName()'],
        ];
    }

    #[DataProvider('classExpressions')]
    public function test_class_loads_without_wordpress(string $expr): void
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

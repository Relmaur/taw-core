<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Schema classes omit the `if (!defined('ABSPATH')) exit;` guard so
 * bin/taw (schema:validate, from Step 4) can build and check definitions
 * before WordPress boots — the guard's `exit` would silently kill the
 * command. Each expression runs in a fresh PHP process with no ABSPATH,
 * same approach as Content\PreBootAutoloadTest.
 */
final class PreBootAutoloadTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function expressions(): array
    {
        return [
            ['\\TAW\\Core\\Schema\\Schema::postType("book")->labels("Book", "Books")->toArray()'],
            ['\\TAW\\Core\\Schema\\Schema::taxonomy("genre")->for("book")->toArray()'],
            ['\\TAW\\Core\\Schema\\Schema::fieldset("fs")->on("book")->fields([\\TAW\\Core\\Schema\\Field::text("x")])->toArray()'],
            ['\\TAW\\Core\\Schema\\Schema::optionsPage("site")->fields([\\TAW\\Core\\Schema\\Field::text("x")])->toArray()'],
            ['\\TAW\\Core\\Schema\\Registry::instance()->add(\\TAW\\Core\\Schema\\Schema::postType("book"))'],
            ['\\TAW\\Core\\Schema\\Source::json("/x.json", \\TAW\\Core\\Schema\\Source::RANK_WP_CONTENT)->describe()'],
            ['(new \\ReflectionClass(\\TAW\\Core\\Schema\\Compiler::class))->getName()'],
            ['\\TAW\\Core\\Schema\\CollisionReport::collisions()'],
            ['\\TAW\\Core\\Schema\\Validator::validate(["version" => 1, "kind" => "post_type", "key" => "book"])'],
            ['\\TAW\\Core\\Schema\\JsonLoader::readFile(' . var_export(__DIR__ . '/fixtures/valid/genre.json', true) . ')'],
            ['new \\TAW\\CLI\\SchemaValidateCommand("/tmp")'],
            ['\\TAW\\Core\\Schema\\Schema::editing()->preset("guided")->content("page", "locked")->problems()'],
            ['\\TAW\\Core\\Editing\\Resolver::resolve(\\TAW\\Core\\Schema\\Schema::editing()->preset("structured"))->toArray()'],
            ['\\TAW\\Core\\Editing\\Presets::layer("design", "locked")'],
        ];
    }

    #[DataProvider('expressions')]
    public function test_schema_classes_load_without_wordpress(string $expr): void
    {
        $autoload = \dirname(__DIR__, 4) . '/vendor/autoload.php';
        $code = sprintf('require %s; $v = %s; echo "LOADED";', var_export($autoload, true), $expr);

        $output = [];
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);
        $joined = implode("\n", $output);

        $this->assertSame(0, $exit, "Subprocess exited non-zero:\n{$joined}");
        $this->assertStringContainsString('LOADED', $joined, "`{$expr}` did not run without ABSPATH:\n{$joined}");
    }
}

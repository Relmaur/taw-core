<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TAW\CLI\SchemaValidateCommand;

/**
 * bin/taw schema:validate — runs WITHOUT WordPress (plain PHPUnit TestCase,
 * no Brain Monkey), which is the point: it must work in CI and pre-commit.
 */
final class SchemaValidateCommandTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Core/Schema/fixtures/valid';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/taw-schema-cli-' . getmypid() . '-' . uniqid();
        mkdir($this->tmp . '/taw-schema', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/taw-schema/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmp . '/taw-schema');
        rmdir($this->tmp);
        parent::tearDown();
    }

    public function test_valid_files_pass(): void
    {
        $tester = new CommandTester(new SchemaValidateCommand($this->tmp));

        $exit = $tester->execute(['paths' => [self::FIXTURES]]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('6 file(s) valid', $tester->getDisplay());
    }

    public function test_defaults_to_the_theme_taw_schema_folder(): void
    {
        copy(self::FIXTURES . '/genre.json', $this->tmp . '/taw-schema/genre.json');
        $tester = new CommandTester(new SchemaValidateCommand($this->tmp));

        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('genre.json', $tester->getDisplay());
    }

    public function test_an_invalid_file_fails_with_its_json_pointer(): void
    {
        file_put_contents($this->tmp . '/taw-schema/bad.json', json_encode([
            'version' => 1, 'kind' => 'fieldset', 'key' => 'fs', 'on' => ['page'],
            'fields'  => [['id' => 'a', 'type' => 'slider']],
        ]));
        $tester = new CommandTester(new SchemaValidateCommand($this->tmp));

        $exit = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('/fields/0/type', $tester->getDisplay());
    }

    public function test_unknown_post_type_targets_are_warnings_not_failures(): void
    {
        // "movie" isn't defined in these files — it might be registered in
        // PHP, so this can only warn.
        copy(self::FIXTURES . '/genre.json', $this->tmp . '/taw-schema/genre.json');
        file_put_contents($this->tmp . '/taw-schema/fs.json', json_encode([
            'version' => 1, 'kind' => 'fieldset', 'key' => 'fs', 'on' => ['movie', 'page', 'page-about.php'],
            'fields'  => [['id' => 'a', 'type' => 'text']],
        ]));
        $tester = new CommandTester(new SchemaValidateCommand($this->tmp));

        $exit = $tester->execute(['--json' => true]);
        $report = json_decode($tester->getDisplay(), true);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertTrue($report['valid']);
        $warnings = implode("\n", $report['warnings']);
        $this->assertStringContainsString('targets "movie"', $warnings);
        $this->assertStringContainsString('taxonomy "genre"', $warnings, 'genre targets "book", not defined in this folder');
        $this->assertStringNotContainsString('"page"', $warnings, 'core post types are never suspicious');
        $this->assertStringNotContainsString('page-about.php', $warnings, 'template filenames are not post types');
    }

    public function test_term_targets_warn_only_for_unknown_taxonomies(): void
    {
        copy(self::FIXTURES . '/genre.json', $this->tmp . '/taw-schema/genre.json');
        file_put_contents($this->tmp . '/taw-schema/fs.json', json_encode([
            'version' => 1, 'kind' => 'fieldset', 'key' => 'fs', 'on' => ['term:genre', 'term:category', 'term:mood'],
            'fields'  => [['id' => 'a', 'type' => 'text']],
        ]));
        $tester = new CommandTester(new SchemaValidateCommand($this->tmp));

        $exit = $tester->execute(['--json' => true]);
        $warnings = implode("\n", json_decode($tester->getDisplay(), true)['warnings']);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('targets terms of "mood"', $warnings);
        $this->assertStringNotContainsString('terms of "genre"', $warnings, 'defined in these files');
        $this->assertStringNotContainsString('terms of "category"', $warnings, 'a core taxonomy');
        $this->assertStringNotContainsString('targets "term:', $warnings, 'term targets are not post types');
    }

    public function test_the_same_entity_in_two_files_is_a_warning(): void
    {
        copy(self::FIXTURES . '/genre.json', $this->tmp . '/taw-schema/a.json');
        copy(self::FIXTURES . '/genre.json', $this->tmp . '/taw-schema/b.json');
        $tester = new CommandTester(new SchemaValidateCommand($this->tmp));

        $tester->execute(['--json' => true]);
        $report = json_decode($tester->getDisplay(), true);

        $this->assertStringContainsString('"taxonomy:genre" is defined in both', implode("\n", $report['warnings']));
    }

    public function test_no_files_is_not_an_error(): void
    {
        $tester = new CommandTester(new SchemaValidateCommand($this->tmp));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('No schema files found', $tester->getDisplay());
    }
}

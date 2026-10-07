<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use TAW\CLI\CanonLawInstallCommand;
use TAW\Tests\TestCase;

final class CanonLawInstallCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/taw-canon-law-cli-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_surface_must_be_canon_law_when_present(): void
    {
        $this->assertNull(CanonLawInstallCommand::surfaceError(['surface' => 'canon_law']));
        $this->assertNull(CanonLawInstallCommand::surfaceError([]), 'an export from before meta existed');
        $this->assertStringContainsString("'catechism'", (string) CanonLawInstallCommand::surfaceError(['surface' => 'catechism']));
    }

    public function test_checksum_accepts_the_upstream_sidecar_naming_and_format(): void
    {
        $file = $this->dir . '/codigo_1983-beta.sqlite';
        file_put_contents($file, 'SQLite format 3' . "\0" . 'payload');
        file_put_contents($this->dir . '/codigo_1983-beta.sha256', hash_file('sha256', $file) . "  codigo_1983-beta.sqlite\n");

        $this->assertNull(CanonLawInstallCommand::checksumError($file));
    }

    public function test_checksum_mismatch_is_reported(): void
    {
        $file = $this->dir . '/codigo_1983-beta.sqlite';
        file_put_contents($file, 'one');
        file_put_contents($file . '.sha256', str_repeat('0', 64));

        $this->assertStringContainsString('Checksum mismatch', (string) CanonLawInstallCommand::checksumError($file));
    }

    public function test_missing_sidecar_is_not_an_error(): void
    {
        $file = $this->dir . '/codigo.sqlite';
        file_put_contents($file, 'x');

        $this->assertNull(CanonLawInstallCommand::checksumError($file));
    }

    public function test_decodes_only_a_canon_law_portable_export(): void
    {
        $good = $this->dir . '/good.json';
        file_put_contents($good, json_encode(['meta' => ['surface' => 'canon_law'], 'divisions' => [], 'canons' => []]));
        $catechism = $this->dir . '/catechism.json';
        file_put_contents($catechism, json_encode(['parts' => [], 'sections' => [], 'chapters' => [], 'paragraphs' => []]));

        $decoded = CanonLawInstallCommand::decodePortableExport($good);
        $this->assertSame('canon_law', $decoded['meta']['surface'] ?? null);
        $this->assertNull(CanonLawInstallCommand::decodePortableExport($catechism));
    }
}

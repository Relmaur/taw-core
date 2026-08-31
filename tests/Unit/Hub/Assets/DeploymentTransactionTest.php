<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Assets;

use TAW\Hub\Assets\DeploymentTransaction;
use TAW\Hub\Assets\PayloadException;
use TAW\Tests\TestCase;

final class DeploymentTransactionTest extends TestCase
{
    private string $tmp;
    private string $buildDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/taw-deploy-' . getmypid() . '-' . uniqid();
        $this->buildDir = $this->tmp . '/theme/public/build';
        @mkdir($this->buildDir, 0777, true);
        file_put_contents($this->buildDir . '/OLD', 'v1');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    /** @param array<string, string> $extra */
    private function makePayload(array $extra = []): string
    {
        $manifest = json_encode([
            'resources/js/app.js' => ['file' => 'assets/app.js', 'css' => ['assets/app.css']],
        ]);

        $files = array_merge([
            'assets/app.js'      => 'console.log("v2")',
            'assets/app.css'     => 'body{color:red}',
            '.vite/manifest.json' => (string) $manifest,
        ], $extra);

        $path = $this->tmp . '/payload-' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function tx(): DeploymentTransaction
    {
        return new DeploymentTransaction($this->buildDir);
    }

    public function test_stage_extracts_and_validates_a_payload(): void
    {
        $result = $this->tx()->stage($this->makePayload());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $result['deployment_id']);
        $this->assertSame(3, $result['files']);
        $this->assertNotEmpty($result['manifest_hash']);

        $this->assertSame('staged', $this->tx()->status($result['deployment_id'])['state']);
    }

    public function test_stage_without_a_manifest_is_rejected(): void
    {
        $path = $this->tmp . '/nomani.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('assets/app.js', 'x');
        $zip->close();

        try {
            $this->tx()->stage($path);
            $this->fail('Expected rejection.');
        } catch (PayloadException $e) {
            $this->assertSame(PayloadException::MANIFEST_MISSING, $e->reason());
        }
    }

    public function test_apply_swaps_the_build_and_leaves_a_rollback(): void
    {
        $tx = $this->tx();
        $staged = $tx->stage($this->makePayload());
        $applied = $tx->apply($staged['deployment_id']);

        $this->assertTrue($applied['applied']);
        $this->assertNotNull($applied['rollback_id']);

        // New build is live, old marker file is gone, old content replaced.
        $this->assertFileExists($this->buildDir . '/assets/app.js');
        $this->assertFileDoesNotExist($this->buildDir . '/OLD');
        $this->assertFileDoesNotExist($this->buildDir . '/.taw-hub-deployment.json');
    }

    public function test_rollback_restores_the_previous_build(): void
    {
        $tx = $this->tx();
        $applied = $tx->apply($tx->stage($this->makePayload())['deployment_id']);

        $tx->rollback((string) $applied['rollback_id']);

        $this->assertFileExists($this->buildDir . '/OLD');
        $this->assertSame('v1', file_get_contents($this->buildDir . '/OLD'));
        $this->assertFileDoesNotExist($this->buildDir . '/assets/app.js');
    }

    public function test_apply_keeps_only_the_newest_rollback(): void
    {
        $tx = $this->tx();
        $tx->apply($tx->stage($this->makePayload())['deployment_id']);
        $tx->apply($tx->stage($this->makePayload())['deployment_id']);
        $tx->apply($tx->stage($this->makePayload())['deployment_id']);

        $rollbacks = glob($this->tmp . '/theme/public/.taw-hub-rollback-*', GLOB_ONLYDIR) ?: [];
        $this->assertCount(1, $rollbacks);
    }

    public function test_apply_with_an_unknown_id_is_rejected(): void
    {
        try {
            $this->tx()->apply('deadbeefdeadbeef');
            $this->fail('Expected rejection.');
        } catch (PayloadException $e) {
            $this->assertSame(PayloadException::DEPLOYMENT_UNKNOWN, $e->reason());
        }
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($path);
    }
}

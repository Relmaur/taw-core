<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Orchestration;

use TAW\Hub\Assets\DeploymentTransaction;
use TAW\Hub\Orchestration\Actions\DeployAssetsAction;
use TAW\Hub\Orchestration\Actions\RollbackAssetsAction;
use TAW\Tests\TestCase;

final class DeployAssetsActionTest extends TestCase
{
    private string $tmp;
    private string $buildDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/taw-deploy-action-' . getmypid() . '-' . uniqid();
        $this->buildDir = $this->tmp . '/theme/public/build';
        @mkdir($this->buildDir, 0777, true);
        file_put_contents($this->buildDir . '/OLD', 'v1');
    }

    protected function tearDown(): void
    {
        $it = @new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it ?: [] as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function payloadZip(): string
    {
        $path = $this->tmp . '/payload.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('assets/app.js', 'console.log("v2")');
        $zip->addFromString('.vite/manifest.json', json_encode(['resources/js/app.js' => ['file' => 'assets/app.js']]));
        $zip->close();

        return $path;
    }

    private function factory(): \Closure
    {
        return fn (): DeploymentTransaction => new DeploymentTransaction($this->buildDir);
    }

    public function test_deploy_stages_and_applies_and_leaves_a_rollback(): void
    {
        $result = (new DeployAssetsAction($this->factory()))->run(['archive_path' => $this->payloadZip()]);

        $this->assertTrue($result->isOk());
        $this->assertArrayHasKey('applied', $result->data());
        $this->assertNotNull($result->data()['applied']['rollback_id']);
        $this->assertFileExists($this->buildDir . '/assets/app.js');
        $this->assertFileDoesNotExist($this->buildDir . '/OLD');
    }

    public function test_deploy_with_apply_false_only_stages(): void
    {
        $result = (new DeployAssetsAction($this->factory()))->run([
            'archive_path' => $this->payloadZip(),
            'apply'        => false,
        ]);

        $this->assertTrue($result->isOk());
        $this->assertArrayNotHasKey('applied', $result->data());
        $this->assertFileExists($this->buildDir . '/OLD'); // untouched
    }

    public function test_a_missing_archive_path_fails_cleanly(): void
    {
        $result = (new DeployAssetsAction($this->factory()))->run(['archive_path' => '/no/such/file.zip']);

        $this->assertFalse($result->isOk());
    }

    public function test_rollback_action_restores_the_previous_build(): void
    {
        $deploy = (new DeployAssetsAction($this->factory()))->run(['archive_path' => $this->payloadZip()]);
        $rollbackId = $deploy->data()['applied']['rollback_id'];

        $result = (new RollbackAssetsAction($this->factory()))->run(['rollback_id' => (string) $rollbackId]);

        $this->assertTrue($result->isOk());
        $this->assertFileExists($this->buildDir . '/OLD');
    }
}

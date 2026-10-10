<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use TAW\CLI\ComposerScripts;
use TAW\Tests\TestCase;

/**
 * The post-update-cmd hook: `composer update` runs the theme's pending
 * migrations, quietly when there are none, never failing the update.
 */
final class ComposerScriptsTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/taw-composer-' . getmypid() . '-' . uniqid();
        @mkdir($this->dir . '/vendor', 0777, true);
        $this->lines = [];
    }

    protected function tearDown(): void
    {
        putenv('TAW_NO_UPGRADE');
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** A stand-in for Composer\Script\Event. @param array<string, mixed> $extra */
    private function event(array $extra = []): object
    {
        $test = $this;
        $vendor = $this->dir . '/vendor';
        $io = new class ($test) {
            public function __construct(private ComposerScriptsTest $t) {}
            public function write(string $line): void { $this->t->record($line); }
        };
        $composer = new class ($vendor, $extra) {
            /** @param array<string, mixed> $extra */
            public function __construct(private string $vendor, private array $extra) {}
            public function getConfig(): object { $v = $this->vendor; return new class ($v) { public function __construct(private string $v) {} public function get(string $k): string { return $this->v; } }; }
            public function getPackage(): object { $e = $this->extra; return new class ($e) { /** @param array<string, mixed> $e */ public function __construct(private array $e) {} /** @return array<string, mixed> */ public function getExtra(): array { return $this->e; } }; }
        };

        return new class ($io, $composer) {
            public function __construct(private object $io, private object $composer) {}
            public function getIO(): object { return $this->io; }
            public function getComposer(): object { return $this->composer; }
        };
    }

    public function record(string $line): void
    {
        $this->lines[] = $line;
    }

    public function test_update_runs_the_pending_migrations(): void
    {
        file_put_contents($this->dir . '/AGENTS.md', "# old full copy\n");
        ComposerScripts::postUpdate($this->event());

        $out = implode("\n", $this->lines);
        $this->assertStringContainsString('✓ 1.91.0/agent-docs', $out);
        $this->assertStringContainsString('✓ 1.89.0/site-skills', $out);
        $this->assertStringContainsString('taw:agent-doc', (string) file_get_contents($this->dir . '/AGENTS.md'));

        $this->lines = [];
        ComposerScripts::postUpdate($this->event());
        $this->assertSame([], $this->lines, 'quiet when nothing is pending');
    }

    public function test_it_can_be_turned_off(): void
    {
        file_put_contents($this->dir . '/AGENTS.md', "# old full copy\n");
        ComposerScripts::postUpdate($this->event(['taw' => ['upgrade' => false]]));
        $this->assertStringContainsString('migrations skipped', implode("\n", $this->lines));
        $this->assertStringContainsString('# old full copy', (string) file_get_contents($this->dir . '/AGENTS.md'));

        putenv('TAW_NO_UPGRADE=1');
        $this->lines = [];
        ComposerScripts::postUpdate($this->event());
        $this->assertStringContainsString('migrations skipped', implode("\n", $this->lines));
    }
}

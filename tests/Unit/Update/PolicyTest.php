<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Update;

use Symfony\Component\Console\Tester\CommandTester;
use TAW\CLI\PolicyCommand;
use TAW\Tests\TestCase;
use TAW\Update\Policy;

/**
 * taw.json's "update" settings: defaults, validation, the constraint an
 * update asks Composer for, and the policy command's words.
 */
final class PolicyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/taw-policy-' . getmypid() . '-' . uniqid();
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_no_file_means_the_defaults(): void
    {
        $p = Policy::load($this->dir);
        $this->assertFalse($p->hasFile());
        $this->assertTrue($p->valid());
        $this->assertSame(['core' => 'minor', 'scaffold' => 'auto', 'manifests' => 'add+bump', 'docs' => 'framework-sections', 'checks' => ['lint', 'phpstan', 'test', 'build'], 'deliver' => 'pr'], $p->toArray());
        $this->assertTrue($p->scaffold() && $p->manifests() && $p->docs());
    }

    public function test_settings_from_the_file_and_defaults_for_the_rest(): void
    {
        file_put_contents($this->dir . '/taw.json', '{"update": {"core": "patch", "checks": ["lint"], "deliver": "branch"}, "other": {"kept": true}}');
        $p = Policy::load($this->dir);
        $this->assertTrue($p->valid(), implode('; ', $p->errors()));
        $this->assertSame('patch', $p->core());
        $this->assertSame(['lint'], $p->checks());
        $this->assertSame('branch', $p->deliver());
        $this->assertTrue($p->isSet('core'));
        $this->assertFalse($p->isSet('scaffold'));
        $this->assertSame('auto', $p->toArray()['scaffold']);
    }

    public function test_errors_are_named_and_never_half_applied(): void
    {
        $p = Policy::fromArray(['core' => 'major', 'checks' => ['lint', 'deploy'], 'deliver' => true, 'speed' => 'fast', 'scaffold' => 'off']);
        $this->assertFalse($p->valid());
        $this->assertCount(4, $p->errors());
        $this->assertStringContainsString('"core" is "major"; it must be one of: patch, minor, pinned:<version>', implode("\n", $p->errors()));
        $this->assertStringContainsString('unknown check "deploy"', implode("\n", $p->errors()));
        $this->assertStringContainsString('unknown setting "speed"', implode("\n", $p->errors()));
        $this->assertFalse($p->scaffold(), 'valid settings still read, but an invalid policy stops updates');

        file_put_contents($this->dir . '/taw.json', '{"update": ');
        $this->assertStringContainsString("isn't valid JSON", implode('', Policy::load($this->dir)->errors()));
        $this->assertStringContainsString('a pin is "pinned:1.90.0"', implode('', Policy::fromArray(['core' => 'pinned:latest'])->errors()));
    }

    public function test_the_constraint_an_update_asks_for(): void
    {
        $this->assertSame('^1.90', Policy::fromArray([])->coreConstraint('v1.90.0'));
        $this->assertSame('~1.90.2', Policy::fromArray(['core' => 'patch'])->coreConstraint('1.90.2'));
        $pinned = Policy::fromArray(['core' => 'pinned:v1.88.0']);
        $this->assertSame('1.88.0', $pinned->coreConstraint('1.90.0'));
        $this->assertSame('1.88.0', $pinned->pinnedVersion());
        $this->assertSame('taw/core stays at exactly 1.88.0', $pinned->explain('core'));
    }

    public function test_the_schema_file_matches_the_settings(): void
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/resources/schema/taw-json-1.0.json'), true);
        $props = $schema['properties']['update']['properties'];
        $this->assertSame(array_keys(Policy::SETTINGS), array_keys($props));
        foreach (Policy::SETTINGS as $key => $setting) {
            $this->assertSame($setting['default'], $props[$key]['default'], $key);
            if (isset($props[$key]['enum'])) {
                $this->assertSame($setting['values'], $props[$key]['enum'], $key);
            }
        }
        $this->assertSame(Policy::SETTINGS['checks']['values'], $props['checks']['items']['enum']);
    }

    public function test_the_policy_command_in_words_json_and_init(): void
    {
        $tester = new CommandTester(new PolicyCommand($this->dir));
        $tester->execute([]);
        $this->assertStringContainsString('No taw.json here, so updates follow the defaults', $tester->getDisplay());
        $this->assertStringContainsString('a pull request is opened for you to merge', $tester->getDisplay());

        $tester->execute(['--init' => true]);
        $this->assertSame(Policy::defaults(), json_decode((string) file_get_contents($this->dir . '/taw.json'), true)['update']);
        $this->assertSame(1, $tester->execute(['--init' => true]), 'never overwrites');

        file_put_contents($this->dir . '/taw.json', '{"update": {"deliver": "pr+merge", "core": "nope"}}');
        $this->assertSame(1, $tester->execute(['--json' => true]));
        $report = json_decode($tester->getDisplay(), true);
        $this->assertFalse($report['valid']);
        $this->assertSame(['deliver'], $report['from_file']);
        $tester->execute([]);
        $this->assertStringContainsString("updates won't run until they're fixed", $tester->getDisplay());
        $this->assertStringContainsString('(taw.json)', $tester->getDisplay());
    }
}

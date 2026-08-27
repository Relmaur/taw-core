<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use ReflectionMethod;
use TAW\CLI\SyncCommand;
use TAW\Tests\TestCase;

/**
 * `.claude/skills/` and `.agents/skills/` are the only paths the agent
 * runtimes auto-discover skills from, so framework-managed and site-authored
 * skills have to live in the same directory. These tests cover the
 * reconciliation that lets `php bin/taw sync` refresh/remove framework skills
 * there without destroying a client's own (`owner: site`) skills — the
 * `type: skills-dir` rules in resources/update-manifest.json § skillsReconcile.
 */
final class SkillsReconcileTest extends TestCase
{
    private string $tmp;
    private string $local;
    private string $canonical;

    /** @var array{ownerKey: string, frameworkValue: string, siteValue: string} */
    private array $cfg = ['ownerKey' => 'owner', 'frameworkValue' => 'taw', 'siteValue' => 'site'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/taw-skills-reconcile-' . getmypid() . '-' . uniqid();
        $this->local = $this->tmp . '/local';
        $this->canonical = $this->tmp . '/canonical';

        // Canonical scaffold ships two framework skills.
        $this->writeSkill($this->canonical, 'audit-seo', 'taw', 'canonical v2');
        $this->writeSkill($this->canonical, 'build-page', 'taw', 'canonical');

        // Client tree: one framework skill is stale-but-current, one is an
        // older copy, one was retired upstream, one is site-authored, and one
        // is an unmarked mystery folder.
        $this->writeSkill($this->local, 'audit-seo', 'taw', 'canonical');          // differs -> overwrite
        $this->writeSkill($this->local, 'build-page', 'taw', 'canonical');         // identical -> left alone
        $this->writeSkill($this->local, 'legacy-export', 'taw', 'retired');        // gone upstream -> delete
        $this->writeSkill($this->local, 'publish-news', 'site', 'parish voice');   // site-authored -> preserve
        $this->writeSkill($this->local, 'mystery', null, 'no marker');             // unknown -> warn + preserve
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmp);

        parent::tearDown();
    }

    public function test_plan_refreshes_deletes_and_preserves_the_right_skills(): void
    {
        $plan = $this->plan();

        $this->assertSame(['audit-seo'], $plan['overwrite'], 'only the drifted framework skill is refreshed');
        $this->assertSame(['legacy-export'], $plan['delete'], 'owner: taw skill absent from canonical is removed');
        $this->assertSame(['publish-news'], $plan['preserve'], 'owner: site skill is preserved');
        $this->assertSame(['mystery'], $plan['warn'], 'unmarked skill is flagged, not deleted');
    }

    public function test_apply_executes_the_plan_on_disk(): void
    {
        $entry = ['path' => '.claude/skills/', 'type' => 'skills-dir'];
        $plan = $this->plan();

        $apply = new ReflectionMethod(SyncCommand::class, 'applySkillsReconcile');
        $apply->invoke(new SyncCommand($this->local), $entry, $this->canonical, $plan);

        $skills = $this->local . '/.claude/skills';

        $this->assertDirectoryDoesNotExist($skills . '/legacy-export', 'retired framework skill removed');
        $this->assertDirectoryExists($skills . '/publish-news', 'site skill untouched');
        $this->assertDirectoryExists($skills . '/mystery', 'unmarked skill untouched');
        $this->assertStringContainsString(
            'canonical v2',
            (string) file_get_contents($skills . '/audit-seo/SKILL.md'),
            'framework skill refreshed to the canonical copy'
        );
    }

    public function test_skill_owner_reads_the_frontmatter_marker(): void
    {
        $owner = new ReflectionMethod(SyncCommand::class, 'skillOwner');
        $cmd = new SyncCommand($this->local);
        $base = $this->local . '/.claude/skills';

        $this->assertSame('site', $owner->invoke($cmd, $base . '/publish-news', 'owner'));
        $this->assertSame('taw', $owner->invoke($cmd, $base . '/audit-seo', 'owner'));
        $this->assertNull($owner->invoke($cmd, $base . '/mystery', 'owner'));
        $this->assertNull($owner->invoke($cmd, $base . '/does-not-exist', 'owner'));
    }

    /**
     * @return array{overwrite: list<string>, delete: list<string>, preserve: list<string>, warn: list<string>}
     */
    private function plan(): array
    {
        $method = new ReflectionMethod(SyncCommand::class, 'planSkillsReconcile');

        /** @var array{overwrite: list<string>, delete: list<string>, preserve: list<string>, warn: list<string>} $plan */
        $plan = $method->invoke(
            new SyncCommand($this->local),
            ['path' => '.claude/skills/', 'type' => 'skills-dir'],
            $this->canonical,
            $this->cfg
        );

        return $plan;
    }

    private function writeSkill(string $root, string $name, ?string $owner, string $body): void
    {
        $dir = $root . '/.claude/skills/' . $name;
        @mkdir($dir, 0777, true);

        $frontmatter = "---\nname: {$name}\n";
        if ($owner !== null) {
            $frontmatter .= "owner: {$owner}\n";
        }
        $frontmatter .= "---\n\n{$body}\n";

        file_put_contents($dir . '/SKILL.md', $frontmatter);
    }

    private function rmrf(string $dir): void
    {
        if (is_dir($dir)) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}

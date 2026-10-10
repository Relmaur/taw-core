<?php

declare(strict_types=1);

namespace TAW\Update\Migrations;

use TAW\CLI\SyncCommand;
use TAW\Update\Migration;
use TAW\Update\MigrationResult;

/**
 * taw/core 1.89.0: the site skills taw/core ships (resolve-comments,
 * perf-audit…) are installed in the theme's .claude/skills/, where Claude
 * Code finds them. Refreshes them when taw/core's copy changed.
 */
final class SiteSkills implements \TAW\Update\Migration
{
    public function id(): string
    {
        return '1.89.0/site-skills';
    }

    public function title(): string
    {
        return "Install or refresh taw/core's site skills in .claude/skills/";
    }

    public function themeKind(): string
    {
        return 'any';
    }

    public function explain(): string
    {
        return <<<'TXT'
            What: copies the site skills taw/core ships (vendor/taw/core/resources/skills/: resolve-comments,
            perf-audit) into the theme's .claude/skills/, or refreshes them when taw/core's copy changed.
            A skill of the same name the site wrote itself (owner: site in its SKILL.md) is kept.

            Why: Claude Code finds skills in the theme folder; taw-fleet starts it there (X, a).

            By hand: vendor/bin/taw skills:sync --apply, then commit .claude/skills/.

            Undo: delete the skill folders (they come back on the next update unless a same-named skill of
            the site's own, marked owner: site, takes the name).
            TXT;
    }

    public function pending(string $themeDir): bool
    {
        return $this->plan($themeDir)['overwrite'] !== [];
    }

    public function run(string $themeDir): MigrationResult
    {
        $sync = new SyncCommand($themeDir);
        $core = SyncCommand::coreSkills();
        $entry = ['path' => '.claude/skills/', 'type' => 'skills-dir'];
        $plan = $this->plan($themeDir);
        $sync->applySkillsReconcile($entry, null, $plan, $core);

        $manual = [];
        foreach ($plan['clash'] as $name) {
            $manual[] = "The site has its own skill named {$name} (owner: site), so taw/core's isn't installed. Rename the site's folder in .claude/skills/ to get both.";
        }

        return new MigrationResult(array_map(fn (string $n) => '.claude/skills/' . $n, $plan['overwrite']), $manual);
    }

    /** @return array{overwrite: list<string>, delete: list<string>, preserve: list<string>, warn: list<string>, clash: list<string>} */
    private function plan(string $themeDir): array
    {
        $sync = new SyncCommand($themeDir);

        return $sync->planSkillsReconcile(['path' => '.claude/skills/', 'type' => 'skills-dir'], null, $sync->skillsReconcileConfig($sync->loadManifest() ?? []), SyncCommand::coreSkills());
    }
}

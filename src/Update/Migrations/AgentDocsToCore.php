<?php

declare(strict_types=1);

namespace TAW\Update\Migrations;

use TAW\CLI\Application;
use TAW\Update\AgentDocs;
use TAW\Update\MigrationResult;

/**
 * taw/core 1.91.0: the theme's agent docs become short site-owned files that
 * import or point to taw/core's (AgentDocs).
 */
final class AgentDocsToCore implements \TAW\Update\Migration
{
    public function id(): string
    {
        return '1.91.0/agent-docs';
    }

    public function title(): string
    {
        return "Point AGENTS.md, CLAUDE.md and the Copilot/Windsurf rules at taw/core's";
    }

    public function themeKind(): string
    {
        return 'any';
    }

    public function explain(): string
    {
        return <<<'TXT'
            What: replaces the theme's full copies of the framework's agent docs (AGENTS.md, CLAUDE.md,
            .github/copilot-instructions.md, .windsurfrules) with short files: CLAUDE.md imports taw/core's
            (@vendor/taw/core/resources/agents/<classic|block>/CLAUDE.md), the others point to it. Each has a
            "This site" section for the site's own notes; updates never change these files again.

            Why: the framework's docs then update with composer update taw/core, and an update never needs
            anyone to review a 2,000-line diff (on the live fleet no site had edited its copy).

            By hand: php bin/taw docs:sync --apply; move any notes you had added into "This site"; commit.

            Undo: git checkout <the commit before> -- AGENTS.md CLAUDE.md .github/copilot-instructions.md .windsurfrules
            TXT;
    }

    public function pending(string $themeDir): bool
    {
        $plan = $this->docs($themeDir)->plan();

        return $plan['convert'] !== [] || $plan['create'] !== [];
    }

    public function run(string $themeDir): MigrationResult
    {
        $docs = $this->docs($themeDir);

        return new MigrationResult($docs->apply($docs->plan()));
    }

    private function docs(string $themeDir): AgentDocs
    {
        return new AgentDocs($themeDir, Application::isBlockTheme($themeDir) ? 'block' : 'classic');
    }
}

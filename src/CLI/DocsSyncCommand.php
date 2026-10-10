<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TAW\Update\AgentDocs;

/**
 * `bin/taw docs:sync`: turns the theme's full copies of the framework's agent
 * docs into short site-owned files that import or point to taw/core's copy
 * (which then updates with `composer update taw/core`). See AgentDocs.
 */
final class DocsSyncCommand extends Command
{
    public function __construct(private string $themeDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('docs:sync')
            ->setDescription("Point the theme's agent docs at taw/core's (check only; --apply writes)")
            ->setHelp(
                "AGENTS.md, CLAUDE.md and the Copilot/Windsurf rules: the framework's part ships with taw/core\n" .
                "and updates with it. The theme keeps short files that import or point to it, with a\n" .
                "\"This site\" section for its own notes; updates never change those.\n\n" .
                "This finds old full copies and replaces them (--apply). Their previous text stays in git\n" .
                "history (git log -p -- AGENTS.md); undo with git checkout <commit> -- AGENTS.md.\n\n" .
                "  <info>php bin/taw docs:sync</info>           what would change\n" .
                "  <info>php bin/taw docs:sync --apply</info>   convert them\n" .
                "  <info>php bin/taw docs:sync --json</info>    for scripts"
            )
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the changes')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $docs = new AgentDocs($this->themeDir, Application::isBlockTheme($this->themeDir) ? 'block' : 'classic');
        $plan = $docs->plan();
        $written = $input->getOption('apply') ? $docs->apply($plan) : [];

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($plan + ['applied' => $written], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }
        $done = $written !== [];
        foreach ($plan['convert'] as $path) {
            $output->writeln(($done ? '  <info>✓ converted</info> ' : '  <comment>↻ to convert</comment> ') . $path . ' <comment>(a full copy of the framework docs)</comment>');
        }
        foreach ($plan['create'] as $path) {
            $output->writeln(($done ? '  <info>✓ created</info> ' : '  <comment>+ to create</comment> ') . $path);
        }
        foreach ($plan['current'] as $path) {
            $output->writeln('  <info>✓</info> ' . $path . ' (points to taw/core)');
        }
        if (!$done && ($plan['convert'] !== [] || $plan['create'] !== [])) {
            $output->writeln('');
            $output->writeln('Run <info>php bin/taw docs:sync --apply</info>, then review and commit. Move any notes of your own into "This site".');
        }

        return Command::SUCCESS;
    }
}

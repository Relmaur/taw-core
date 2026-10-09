<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Installs the site skills taw/core ships (`resources/skills/<name>/`, e.g.
 * resolve-comments, perf-audit) into this theme's `.claude/skills/`, where
 * Claude Code finds them when it starts in the theme folder (taw-fleet's X
 * and skill picker do).
 *
 * The one way block themes get them (sync is for taw-theme-based themes);
 * classic themes get them from `sync` too. No network: the skills come from
 * the installed taw/core, so `composer update taw/core` then `skills:sync`
 * brings them current. Same per-skill rules as sync: a site's own skill of
 * the same name (`owner: site`) is kept and reported; skills taw/core doesn't
 * ship are never touched.
 */
class SkillsSyncCommand extends Command
{
    private const PATH = '.claude/skills/';

    public function __construct(private string $themeDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('skills:sync')
            ->setDescription("Install taw/core's site skills into .claude/skills/ (check only; --apply writes)")
            ->setHelp(
                "Compares .claude/skills/ with the site skills this taw/core ships (resources/skills/).\n" .
                "Without --apply it only reports. A skill of the same name the site wrote itself\n" .
                "(owner: site in its SKILL.md) is kept; other skills in the folder are never touched.\n\n" .
                "  <info>php bin/taw skills:sync</info>           what would change\n" .
                "  <info>php bin/taw skills:sync --apply</info>   install or refresh them\n" .
                "  <info>php bin/taw skills:sync --json</info>    for scripts (taw-fleet)"
            )
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the changes')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        $sync = new SyncCommand($this->themeDir);
        $manifest = $sync->loadManifest();
        $cfg = $sync->skillsReconcileConfig($manifest ?? []);
        $core = SyncCommand::coreSkills(self::PATH);
        $entry = ['path' => self::PATH, 'type' => 'skills-dir'];

        $plan = $sync->planSkillsReconcile($entry, null, $cfg, $core);
        $current = array_values(array_diff(array_keys($core), $plan['overwrite'], $plan['clash']));
        $applied = $apply && $plan['overwrite'] !== [];
        if ($applied) {
            $sync->applySkillsReconcile($entry, null, $plan, $core);
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'skills' => array_keys($core),
                'install' => $plan['overwrite'],
                'current' => $current,
                'clash' => $plan['clash'],
                'applied' => $applied,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ($core === []) {
            $io->warning('This taw/core ships no site skills (resources/skills/ is empty).');

            return Command::SUCCESS;
        }
        foreach ($plan['overwrite'] as $name) {
            $io->writeln(($applied ? '  <info>✓ installed</info> ' : '  <comment>↻ to install</comment> ') . $name);
        }
        foreach ($current as $name) {
            $io->writeln('  <info>✓</info> ' . $name . ' (current)');
        }
        foreach ($plan['clash'] as $name) {
            $io->writeln('  <comment>! ' . $name . '</comment>: the site has its own skill by this name (owner: site), kept; rename it to get taw/core\'s');
        }
        if ($plan['overwrite'] !== [] && !$applied) {
            $io->writeln('');
            $io->writeln('Run <info>php bin/taw skills:sync --apply</info> to install them.');
        }

        return Command::SUCCESS;
    }
}

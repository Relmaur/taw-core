<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TAW\Update\Migrations;

/**
 * `bin/taw upgrade`: the changes taw/core releases make to a theme, done by
 * code (migrations) instead of UPGRADING.md checks. Run it after
 * `composer update taw/core`; `bin/taw update` will run it for you.
 */
final class UpgradeCommand extends Command
{
    public function __construct(private string $themeDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('upgrade')
            ->setDescription('Run the migrations this theme still needs after a taw/core update (check only; --apply runs them)')
            ->setHelp(
                "Each taw/core release that changes something in a theme ships a migration: it finds its own work,\n" .
                "so running it again does nothing, and anything it shouldn't decide (a file the site edited) is left\n" .
                "as is, with the steps for a person. A migration that taw.json turns off (\"scaffold\" or \"docs\":\n" .
                "\"off\") is listed as held back and doesn't run; --explain <id> says how to do it by hand.\n\n" .
                "  <info>vendor/bin/taw upgrade</info>                       what's pending\n" .
                "  <info>vendor/bin/taw upgrade --apply</info>               run them\n" .
                "  <info>vendor/bin/taw upgrade --explain <id></info>        what one does, why, by hand, undo\n" .
                "  <info>vendor/bin/taw upgrade --json</info>                for scripts and taw-fleet"
            )
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Run the pending migrations')
            ->addOption('explain', null, InputOption::VALUE_REQUIRED, 'Explain one migration (all of them: --explain all)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $explain = $input->getOption('explain');
        if (is_string($explain) && $explain !== '') {
            $list = $explain === 'all' ? Migrations::all() : array_filter([Migrations::find($explain)]);
            if ($list === []) {
                $output->writeln('<error>No migration "' . $explain . '".</error> Known: ' . implode(', ', array_map(fn ($m) => $m->id(), Migrations::all())));

                return Command::FAILURE;
            }
            foreach ($list as $m) {
                $output->writeln('<info>' . $m->id() . '</info>  ' . $m->title());
                $output->writeln('');
                $output->writeln($m->explain());
                $output->writeln('');
            }

            return Command::SUCCESS;
        }

        $pending = Migrations::pending($this->themeDir);
        $held = Migrations::held($this->themeDir);
        $results = [];
        if ($input->getOption('apply')) {
            foreach ($pending as $m) {
                $results[$m->id()] = $m->run($this->themeDir)->toArray();
            }
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'pending' => array_map(fn ($m) => ['id' => $m->id(), 'title' => $m->title()], $pending),
                'applied' => $results,
                'manual' => array_merge([], ...array_map(fn ($r) => $r['manual'], array_values($results))),
                'held' => array_map(fn ($h) => ['id' => $h['migration']->id(), 'title' => $h['migration']->title(), 'setting' => $h['setting'], 'note' => Migrations::heldNote($h['migration'], $h['setting'])], $held),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        foreach ($held as $h) {
            $output->writeln('  <comment>–</comment> ' . Migrations::heldNote($h['migration'], $h['setting']));
        }
        if ($pending === []) {
            $output->writeln('<info>✓</info> Nothing ' . ($held === [] ? '' : 'else ') . 'to migrate: this theme is up to date with its taw/core' . ($held === [] ? '' : ' and its taw.json') . '.');

            return Command::SUCCESS;
        }
        foreach ($pending as $m) {
            $result = $results[$m->id()] ?? null;
            if ($result === null) {
                $output->writeln('  <comment>↻</comment> <info>' . $m->id() . '</info>  ' . $m->title());
                continue;
            }
            $output->writeln('  <info>✓ ' . $m->id() . '</info>  ' . $m->title() . ($result['changed'] !== [] ? ': ' . implode(', ', $result['changed']) : ''));
            foreach ($result['manual'] as $step) {
                $output->writeln('      <comment>For you:</comment> ' . $step);
            }
        }
        $output->writeln('');
        $output->writeln($results === []
            ? 'Run <info>vendor/bin/taw upgrade --apply</info>. What each one does, why, by hand and how to undo: <info>vendor/bin/taw upgrade --explain <id></info>.'
            : 'Review the changes (git diff) and commit them. Steps marked "For you" stay listed until they\'re done.');

        return Command::SUCCESS;
    }
}
